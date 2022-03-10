<?php

namespace codemonauts\elastic\utilities;

use codemonauts\elastic\Elastic;
use Craft;
use craft\base\Utility;

class IndexUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('elastic', 'Elasticsearch Indexes');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'elastic-indexes';
    }

    /**
     * @inheritdoc
     */
    public static function iconPath()
    {
        return Craft::getAlias('@codemonauts/elastic/icon-mask.svg');
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $indexService = Elastic::$plugin->getIndexes();
        $sites = Craft::$app->getSites()->getAllSites();

        $indexStatus = [];
        foreach ($sites as $site) {
            $stats = $indexService->stats($site);
            $indexName = $indexService->getCurrentIndex($site);
            $indexStatus[] = [
                'site' => $site,
                'alias' => $indexService->getIndexName($site),
                'index' => $indexName,
                'elements' => $stats['indices'][$indexName]['total']['docs']['count'],
                'storage' => $stats['indices'][$indexName]['total']['store']['size_in_bytes'],
            ];
        }

        return Craft::$app->getView()->renderTemplate('elastic/utilities', [
            'indexStatus' => $indexStatus,
        ]);
    }
}