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
use yii\console\Controller;

class ElementsController extends Controller
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
            $count = (new Query())->from($elementsTable)->where([
                'type' => $elementType,
            ])->count();

            if ($this->confirm("Index all $count elements of type '$elementType'? ")) {
                $elementTypesToIndex[] = $elementType;
            }
        }

        foreach ($elementTypesToIndex as $type) {
            $query = (new Query())->select(['id', 'type'])
                ->from($elementsTable)
                ->where([
                    'type' => $type,
                ])
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
                            'siteId' => $siteHandle,
                        ]);
                        try {
                            $queue->priority($priority)->push($job);
                        } catch (NotSupportedException) {
                            $queue->push($job);
                        }
                    } else {
                        $elementsOfType = $element['type']::find()
                            ->drafts(null)
                            ->id($element['id'])
                            ->siteId($siteHandle)
                            ->status(null)
                            ->provisionalDrafts(null)
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
    }
}