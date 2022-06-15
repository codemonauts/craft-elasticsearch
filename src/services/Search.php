<?php

namespace codemonauts\elastic\services;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\jobs\DeleteOrphanedIndexes;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQuery;
use craft\errors\SiteNotFoundException;
use craft\events\SearchEvent;
use craft\helpers\ArrayHelper;
use craft\search\SearchQuery;
use craft\services\Search as CraftSearch;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;

/**
 * Handles search in backend with Elasticsearch
 */
class Search extends CraftSearch
{
    /**
     * @var int|null The minimum word length that keywords must be in order to use a full-text search. Defaults to 2.
     */
    public ?int $minFullTextWordLength;

    /**
     * @inheritDoc
     */
    public function indexElementAttributes(ElementInterface $element, ?array $fieldHandles = null): bool
    {
        $elementsService = Elastic::$plugin->getElements();

        // Acquire a lock for this element/site ID
        $mutex = Craft::$app->getMutex();
        $lockKey = "searchindex:$element->id:$element->siteId";

        if (!$mutex->acquire($lockKey)) {
            // Not worth waiting around; for all we know the other process has newer search attributes anyway
            return true;
        }

        $keywords = [];
        $site = Craft::$app->getSites()->getSiteById($element->siteId);
        if (!$site) {
            throw new SiteNotFoundException();
        }

        // Figure out which fields to update, and which to ignore
        /** @var FieldInterface[] $updateFields */
        $updateFields = [];
        if ($element::hasContent() && ($fieldLayout = $element->getFieldLayout()) !== null) {
            foreach ($fieldLayout->getCustomFields() as $field) {
                if ($field->searchable) {
                    $updateFields[] = $field;
                }
            }
        }

        // Clear the element's current search keywords
        $elementsService->delete($element->id, $site);

        // Update the element attributes' keywords
        $searchableAttributes = array_flip($element::searchableAttributes());
        $searchableAttributes['slug'] = true;
        if ($element::hasTitles()) {
            $searchableAttributes['title'] = true;
        }
        foreach (array_keys($searchableAttributes) as $attribute) {
            $keywords['attribute_' . $attribute] = $element->getSearchKeywords($attribute);
        }

        // Update the custom fields' keywords
        foreach ($updateFields as $field) {
            $fieldValue = $element->getFieldValue($field->handle);
            $keywords['field_' . $field->id] = $field->getSearchKeywords($fieldValue, $element);
        }

        // Write keywords to index
        $elementsService->add($element, $site, $keywords);

        // Release the lock
        $mutex->release($lockKey);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function searchElements(ElementQuery $elementQuery): array
    {
        $searchQuery = $elementQuery->search;
        if (is_string($searchQuery)) {
            $searchQuery = new SearchQuery($searchQuery, Craft::$app->getConfig()->getGeneral()->defaultSearchTermOptions);
        } elseif (is_array($searchQuery)) {
            $options = array_merge($searchQuery);
            $searchQuery = ArrayHelper::remove($options, 'query');
            $options = array_merge(Craft::$app->getConfig()->getGeneral()->defaultSearchTermOptions, $options);
            $searchQuery = new SearchQuery($searchQuery, $options);
        }

        $elementQuery = (clone $elementQuery)
            ->search(null)
            ->offset(null)
            ->limit(null);

        $site = Craft::$app->getSites()->getSiteById($elementQuery->siteId);

        // Fire a 'beforeSearch' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
            $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
                'elementQuery' => $elementQuery,
                'query' => $searchQuery,
                'siteId' => $elementQuery->siteId,
            ]));
        }

        // Do the search
        if ($elementQuery !== null) {
            $validIds = $elementQuery->ids();
        } else if (!empty($elementIds)) {
            $validIds = $elementIds;
        } else {
            $validIds = [];
        }
        try {
            $results = Elastic::$plugin->getElements()->search($searchQuery, $validIds, $site);
            $scoresByElementId = [];

            // Loop through results and prepare for return
            foreach ($results['hits']['hits'] as $row) {
                $scoresByElementId[$row['_id']] = $row['_score'];
            }

            // Sort found elementIds by score
            arsort($scoresByElementId);
        } catch (BadRequest400Exception) {
            $scoresByElementId = [];
        }

        // Fire an 'afterSearch' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
            $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
                'elementQuery' => $elementQuery,
                'query' => $searchQuery,
                'siteId' => $elementQuery->siteId,
                'results' => array_keys($scoresByElementId),
            ]));
        }

        return $scoresByElementId;
    }

    /**
     * @inheritDoc
     */
    public function deleteOrphanedIndexes(): void
    {
        Craft::$app->getQueue()->push(new DeleteOrphanedIndexes());
    }
}