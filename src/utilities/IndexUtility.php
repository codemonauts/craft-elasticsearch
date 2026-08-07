<?php

namespace codemonauts\elastic\utilities;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\services\Indexes;
use Craft;
use craft\base\Utility;
use Elasticsearch\Common\Exceptions\ElasticsearchException;
use Elasticsearch\Common\Exceptions\Missing404Exception;

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
    public static function icon(): ?string
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
            try {
                $stats = $indexService->stats($site);
                $indexName = $indexService->getCurrentIndex($site);
                $indexStatus[] = [
                    'site' => $site,
                    'alias' => $indexService->getIndexName($site),
                    'index' => $indexName,
                    'elements' => $stats['indices'][$indexName]['total']['docs']['count'],
                    'storage' => $stats['indices'][$indexName]['total']['store']['size_in_bytes'],
                ];
            } catch (ElasticsearchException $e) {
                // Missing404 (no index for this site yet) is expected. Any other Elasticsearch
                // error — most importantly an unreachable cluster — must not 500 the whole
                // dashboard; degrade to N/A and log the genuine failures.
                if (!$e instanceof Missing404Exception) {
                    Craft::error('Could not read index stats for site ' . $site->id . ': ' . $e->getMessage(), 'elastic');
                }
                $indexStatus[] = [
                    'site' => $site,
                    'alias' => 'N/A',
                    'index' => 'N/A',
                    'elements' => 'N/A',
                    'storage' => 'N/A',
                ];
            }
        }

        // Schema-drift detection (read-only, cached). Only the actionable rows are handed to
        // the template for the warning block.
        $driftWarnings = array_values(array_filter(
            $indexService->detectMappingDrift(),
            static fn(array $drift): bool => $drift['status'] !== Indexes::STATUS_CURRENT
        ));

        return Craft::$app->getView()->renderTemplate('elastic/utilities', [
            'indexStatus' => $indexStatus,
            'driftWarnings' => $driftWarnings,
        ]);
    }
}
