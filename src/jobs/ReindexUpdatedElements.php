<?php

namespace codemonauts\elastic\jobs;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\queue\BaseJob;
use DateTime;

class ReindexUpdatedElements extends BaseJob
{
    /**
     * @var DateTime
     */
    public DateTime $startDate;

    /**
     * @var bool Whether to reindex elements to the database full-text index.
     */
    public bool $toDatabaseIndex = false;

    /**
     * @var bool Whether to reindex elements to the Elasticsearch index.
     */
    public bool $toElasticsearchIndex = false;

    /**
     * @inheritDoc
     */
    public function execute($queue): void
    {
        $query = new Query();
        $query->select(['id', 'type'])
            ->from(Table::ELEMENTS)
            ->where(Db::parseDateParam('dateUpdated', $this->startDate, '>='));

        $elements = $query->all();
        $total = count($elements);

        foreach ($elements as $i => $element) {
            $this->setProgress($queue, ($i + 1) / $total, ($i + 1) . ' / ' . $total);
            if ($this->toDatabaseIndex) {
                Craft::$app->getQueue()->push(new UpdateDatabaseIndex([
                    'elementType' => $element['type'],
                    'elementId' => $element['id'],
                ]));
            }
            if ($this->toElasticsearchIndex) {
                Craft::$app->getQueue()->push(new UpdateElasticsearchIndex([
                    'elementType' => $element['type'],
                    'elementId' => $element['id'],
                ]));
            }
        }
    }

    /**
     * @inheritDoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('elastic', 'Reindex updated elements');
    }
}
