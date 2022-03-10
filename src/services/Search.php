<?php

namespace codemonauts\elastic\services;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\jobs\DeleteOrphanedIndexes;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQuery;
use craft\errors\InvalidFieldException;
use craft\errors\SiteNotFoundException;
use craft\events\SearchEvent;
use craft\helpers\Search as SearchHelper;
use craft\search\SearchQuery;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use yii\base\InvalidConfigException;

/**
 * Handles search in backend with Elasticsearch
 */
class Search extends Component
{
    /**
     * @event SearchEvent The event that is triggered before a search is performed.
     */
    const EVENT_BEFORE_SEARCH = 'beforeSearch';

    /**
     * @event SearchEvent The event that is triggered after a search is performed.
     */
    const EVENT_AFTER_SEARCH = 'afterSearch';

    /**
     * @var bool Whether full-text searches should be used ever. We will always use full-text search :)
     * @since 3.4.10
     */
    public $useFullText = true;

    /**
     * @var int|null The minimum word length that keywords must be in order to use a full-text search. Defaults to 2.
     */
    public $minFullTextWordLength;

    /**
     * Indexes the attributes of a given element defined by its element type.
     *
     * @param ElementInterface $element
     * @param string[]|null $fieldHandles Only for compatibility. We always index all searchable fields and attributes.
     *
     * @return bool Whether the indexing was a success.
     * @throws SiteNotFoundException|InvalidFieldException|InvalidConfigException
     */
    public function indexElementAttributes(ElementInterface $element, array $fieldHandles = null): bool
    {
        $elementsService = Elastic::$plugin->getElements();

        // Acquire a lock for this element/site ID
        $mutex = Craft::$app->getMutex();
        $lockKey = "searchindex:$element->id:$element->siteId";

        if (!$mutex->acquire($lockKey)) {
            // Not worth waiting around; for all we know the other process has newer search attributes anyway
            return true;
        }

        $dirtyKeywords = [];
        $site = Craft::$app->getSites()->getSiteById($element->siteId);
        if (!$site) {
            throw new SiteNotFoundException();
        }

        // Figure out which fields to update, and which to ignore
        /** @var FieldInterface[] $updateFields */
        $updateFields = [];
        if ($element::hasContent() && ($fieldLayout = $element->getFieldLayout()) !== null) {
            foreach ($fieldLayout->getFields() as $field) {
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
            $dirtyKeywords['attribute_' . $attribute] = $element->getSearchKeywords($attribute);
        }

        // Update the custom fields' keywords
        foreach ($updateFields as $field) {
            $fieldValue = $element->getFieldValue($field->handle);
            $dirtyKeywords['field_' . $field->id] = $field->getSearchKeywords($fieldValue, $element);
        }

        // Write keywords to index
        $cleanKeywords = [];
        foreach ($dirtyKeywords as $key => $dirtyKeyword) {
            $cleanKeywords[$key] = SearchHelper::normalizeKeywords($dirtyKeyword, [], true, $site->language);
        }
        $elementsService->add($element, $site, $cleanKeywords);

        // Release the lock
        $mutex->release($lockKey);

        return true;
    }

    /**
     * Indexes the field values for a given element and site.
     *
     * @param int $elementId The ID of the element getting indexed.
     * @param int $siteId The site ID of the content getting indexed.
     * @param array $fields The field values, indexed by field ID.
     *
     * @return bool Whether the indexing was a success.
     * @throws SiteNotFoundException|InvalidFieldException|InvalidConfigException
     * @deprecated in 3.4.0. Use [[indexElementAttributes()]] instead.
     */
    public function indexElementFields(int $elementId, int $siteId, array $fields): bool
    {
        $element = Craft::$app->elements->getElementById($elementId, null, $siteId);

        return $this->indexElementAttributes($element, $fields);
    }

    /**
     * Searches for elements that match the given element query.
     *
     * @param ElementQuery $elementQuery The element query being executed
     *
     * @return array The filtered list of element IDs.
     * @throws InvalidConfigException
     * @since 3.7.14
     */
    public function searchElements(ElementQuery $elementQuery): array
    {
        return $this->_searchElements($elementQuery, null, $elementQuery->search, $elementQuery->siteId, $elementQuery->customFields);
    }

    /**
     * Filters a list of element IDs by a given search query.
     *
     * @param int[] $elementIds The list of element IDs to filter by the search query.
     * @param string|array|SearchQuery $searchQuery The search query (either a string or a SearchQuery instance)
     * @param bool $scoreResults Whether to order the results based on how closely they match the query. (No longer checked.)
     * @param int|int[]|null $siteId The site ID(s) to filter by.
     * @param bool $returnScores Whether the search scores should be included in the results. If true, results will be returned as `element ID => score`.
     * @param FieldInterface[]|null $customFields The custom fields involved in the query.
     *
     * @return array The filtered list of element IDs.
     * @throws InvalidConfigException
     * @deprecated in 3.7.14. Use [[searchElements()]] instead.
     */
    public function filterElementIdsByQuery(array $elementIds, $searchQuery, bool $scoreResults = true, $siteId = null, bool $returnScores = false, ?array $customFields = null): array
    {
        $scoredResults = $this->_searchElements(null, $elementIds, $searchQuery, $siteId, $customFields);
        return $returnScores ? $scoredResults : array_keys($scoredResults);
    }

    /**
     * Filters a list of element IDs by a given search query.
     *
     * @param ElementQuery|null $elementQuery
     * @param int[]|null $elementIds
     * @param string|array|SearchQuery $searchQuery
     * @param int|int[]|null $siteId
     * @param FieldInterface[]|null $customFields
     *
     * @return array
     * @throws InvalidConfigException
     */
    private function _searchElements(?ElementQuery $elementQuery, ?array $elementIds, $searchQuery, $siteId, ?array $customFields): array
    {
        if ($elementQuery !== null) {
            $elementQuery = (clone $elementQuery)
                ->search(null)
                ->offset(null)
                ->limit(null);
        }

        if (is_string($searchQuery)) {
            $searchQuery = new SearchQuery($searchQuery, Craft::$app->getConfig()->getGeneral()->defaultSearchTermOptions);
        } else if (is_array($searchQuery)) {
            $options = $searchQuery;
            $searchQuery = $options['query'];
            unset($options['query']);
            $options = array_merge(Craft::$app->getConfig()->getGeneral()->defaultSearchTermOptions, $options);
            $searchQuery = new SearchQuery($searchQuery, $options);
        }

        $site = Craft::$app->getSites()->getSiteById($siteId);

        // Fire a 'beforeSearch' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
            $this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
                'elementQuery' => $elementQuery,
                'elementIds' => $elementIds,
                'query' => $searchQuery,
                'siteId' => $siteId,
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
        } catch (BadRequest400Exception $e) {
            $scoresByElementId = [];
        }

        // Fire an 'afterSearch' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
            $this->trigger(self::EVENT_AFTER_SEARCH, new SearchEvent([
                'elementQuery' => $elementQuery,
                'elementIds' => array_keys($scoresByElementId),
                'query' => $searchQuery,
                'siteId' => $siteId,
                'results' => array_keys($scoresByElementId),
            ]));
        }

        return $scoresByElementId;
    }

    /**
     * Deletes any search indexes that belong to elements that don’t exist anymore.
     *
     * @since 3.2.10
     */
    public function deleteOrphanedIndexes()
    {
        Craft::$app->getQueue()->push(new DeleteOrphanedIndexes());
    }
}