<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\jobs\UpdateElasticsearchIndex;
use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Console;
use yii\base\NotSupportedException;
use yii\console\ExitCode;

/**
 * Commands to index elements.
 */
class ElementsController extends BaseController
{
    /**
     * Index elements to current index.
     *
     * @param string $siteHandle The site to index. Default '*' to reindex elements of all sites.
     * @param bool $useQueue Whether to use jobs in a queue for indexing.
     * @param string $queue The queue to use.
     * @param int $priority The queue priority to use.
     *
     * @throws \craft\errors\SiteNotFoundException
     */
    public function actionIndex(string $siteHandle = '*', bool $useQueue = true, string $queue = 'queue', int $priority = 2048)
    {
        $search = Elastic::$plugin->getSearch();
        $queue = Craft::$app->$queue;
        $elementsTable = Table::ELEMENTS;

        // Element queries expect a site ID (or '*'), not a handle — passing the handle through
        // would silently match no elements at all.
        if ($siteHandle === '*') {
            $siteId = '*';
        } else {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if (!$site) {
                $this->stderr('No site found with the handle "' . $siteHandle . '".' . PHP_EOL, Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }
            $siteId = $site->id;
        }

        /**
         * @var ElementInterface $elementType
         */
        $elementTypesToIndex = [];
        $elementTypes = Craft::$app->elements->getAllElementTypes();
        foreach ($elementTypes as $elementType) {
            $attributes = $elementType::searchableAttributes();
            if (!$elementType::hasTitles() && count($attributes) === 0) {
                continue;
            }
            $count = (new Query())->from($elementsTable)->where($this->indexableCondition($elementType))->count();

            if ($this->confirm("Index all $count elements of type '$elementType'? ")) {
                $elementTypesToIndex[] = $elementType;
            }
        }

        foreach ($elementTypesToIndex as $type) {
            $query = (new Query())->select(['id', 'type'])
                ->from($elementsTable)
                ->where($this->indexableCondition($type))
                ->orderBy('dateCreated desc');

            $total = $query->count();
            $counter = 0;

            $this->stdout("Index $total elements of type '$type' ..." . PHP_EOL);

            Console::startProgress(0, $total);
            foreach ($query->batch() as $rows) {
                foreach ($rows as $element) {
                    if ($useQueue) {
                        $job = new UpdateElasticsearchIndex([
                            'elementType' => $element['type'],
                            'elementId' => $element['id'],
                            'siteId' => $siteId,
                        ]);
                        try {
                            $queue->priority($priority)->push($job);
                        } catch (NotSupportedException) {
                            $queue->push($job);
                        }
                    } else {
                        $elementsOfType = $element['type']::find()
                            ->id($element['id'])
                            ->siteId($siteId)
                            ->status(null)
                            ->all();

                        foreach ($elementsOfType as $e) {
                            $search->indexElementAttributes($e);
                        }
                    }
                    Console::updateProgress(++$counter, $total);
                }
            }
            Console::endProgress();
        }

        return ExitCode::OK;
    }

    /**
     * The condition that selects the rows of an element type that actually end up in the index.
     *
     * Craft's elements table holds one row per element, including every revision (up to
     * `maxRevisions` per canonical element), every draft and every soft-deleted element. Element
     * queries skip those, so counting or queueing them only produces work that indexes nothing.
     *
     * @param string $elementType The element class to build the condition for.
     *
     * @return array
     */
    private function indexableCondition(string $elementType): array
    {
        return [
            'type' => $elementType,
            'revisionId' => null,
            'draftId' => null,
            'dateDeleted' => null,
            'archived' => false,
        ];
    }
}
