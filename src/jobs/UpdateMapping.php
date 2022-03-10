<?php

namespace codemonauts\elastic\jobs;

use codemonauts\elastic\Elastic;
use Craft;
use craft\queue\BaseJob;

class UpdateMapping extends BaseJob
{
    /**
     * @inheritDoc
     */
    public function execute($queue)
    {
        $sites = Craft::$app->sites->getAllSites();
        $indexes = Elastic::$plugin->getIndexes();

        foreach ($sites as $site) {
            $indexes->updateMapping($site);
        }
    }

    /**
     * @inheritDoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('elastic', 'Updating Elasticsearch mapping');
    }
}
