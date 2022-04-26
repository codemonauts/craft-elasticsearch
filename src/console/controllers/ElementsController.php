<?php

namespace codemonauts\elastic\console\controllers;

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
     * Reindex elements to current index.
     *
     * @param string $siteHandle The site to index. Default '*' to reindex elements of all sites.
     * @param string $channel The queue channel to use.
     * @param int $priority The queue priority to use.
     */
    public function actionReindex(string $siteHandle = '*', string $channel = 'queue', int $priority = 2048)
    {
        $queue = Craft::$app->$channel;
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

            if ($this->confirm("Reindex all $count elements of type '$elementType'? ")) {
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

            $this->stdout("Reindex $total elements of type '$type' ..." . PHP_EOL);

            Console::startProgress(0, $total);
            foreach ($query->batch() as $rows) {
                foreach ($rows as $element) {
                    $job = new UpdateElasticsearchIndex([
                        'elementType' => $element['type'],
                        'elementId' => $element['id'],
                        'siteId' => $siteHandle,
                    ]);
                    try {
                        $queue->priority($priority)->push($job);
                    } catch (NotSupportedException $e) {
                        $queue->push($job);
                    }
                    Console::updateProgress(++$counter, $total);
                }
            }
            Console::endProgress();
        }
    }
}