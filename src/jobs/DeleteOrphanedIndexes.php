<?php

namespace codemonauts\elastic\jobs;

use codemonauts\elastic\Elastic;
use Craft;
use craft\db\Table;
use craft\queue\BaseJob;

class DeleteOrphanedIndexes extends BaseJob
{
    /**
     * @inheritDoc
     */
    public function execute($queue)
    {
        $sites = Craft::$app->getSites()->getAllSites();
        $elements = Elastic::$plugin->getElements();
        $this->setProgress($queue, 1);
        $elementsTable = Table::ELEMENTS;
        $existingIds = Craft::$app->getDb()->createCommand("select id from $elementsTable")->queryColumn();

        $total = count($sites);
        $counter = 0;
        foreach ($sites as $site) {
            $indexedIds = $elements->getAllIndexedIds($site);
            $orphanedIds = array_diff($indexedIds, $existingIds);
            $elements->bulkDelete($orphanedIds, $site);
            $this->setProgress($queue, (++$counter / $total), 'Site ' . $counter . ' of ' . $total);
        }
    }

    /**
     * @inheritDoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('elastic', 'Delete orphaned elements from search index');
    }
}
