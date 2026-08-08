<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\services\Indexes;
use Craft;
use craft\errors\SiteNotFoundException;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use craft\search\SearchQuery;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\console\widgets\Table;

/**
 * Commands to inspect and maintain the indexes.
 */
class IndexController extends BaseController
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'stats';

    /**
     * @var bool Whether to delete only orphaned indexes and not the current one.
     */
    public bool $orphanedOnly = false;

    /**
     * @var bool Whether to show all and not only those relevant for this configuration.
     */
    public bool $all = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'delete') {
            $options[] = 'orphanedOnly';
        }
        if ($actionID === 'list') {
            $options[] = 'all';
        }
        return $options;
    }

    /**
     * Outputs some stats of all or a specific indices.
     *
     * @param string $siteHandle Default '*' to get stats of all sites.
     *
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionStats(string $siteHandle = '*')
    {
        $indexes = Elastic::$plugin->getIndexes();
        $sites = $this->_getSites($siteHandle);
        foreach ($sites as $site) {
            try {
                $indexName = $indexes->getCurrentIndex($site);
                $result = $indexes->stats($site);
                $this->stdout('Index stats of site "');
                $this->stdout($site->handle, Console::FG_YELLOW);
                $this->stdout('":' . PHP_EOL);
                $this->stdout('Current index in use: ' . $indexName . PHP_EOL);
                $this->stdout('Elements in index: ' . $result['indices'][$indexName]['total']['docs']['count'] . PHP_EOL);
                $this->stdout('Stored data: ' . Craft::$app->getFormatter()->asShortSize($result['indices'][$indexName]['total']['store']['size_in_bytes']) . PHP_EOL . PHP_EOL);
            } catch (Missing404Exception) {
                $this->stderr('Index for site "');
                $this->stderr($site->handle, Console::FG_YELLOW);
                $this->stderr('" not found.' . PHP_EOL);
            }
        }
    }

    /**
     * Outputs the source of an element in the index.
     *
     * @param int $elementId The element ID to output.
     * @param string $siteHandle The site to use.
     *
     * @throws \craft\errors\SiteNotFoundException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionSource(int $elementId, string $siteHandle = '*')
    {
        $element = Craft::$app->getElements()->getElementById($elementId);
        if (!$element) {
            $this->stderr("Element with ID $elementId not found!" . PHP_EOL, Console::FG_RED);
            return;
        }

        $indexes = Elastic::$plugin->getIndexes();
        $sites = $this->_getSites($siteHandle);
        $table = new Table();
        $table->setHeaders([
            'Field handle',
            'Source',
            'Analyzer',
        ]);

        foreach ($sites as $site) {
            $element = Craft::$app->getElements()->getElementById($elementId, null, $site->id);
            $rows = [];
            $this->stdout('Index source of element "');
            $this->stdout($element, Console::FG_YELLOW);
            $this->stdout('" for site "');
            $this->stdout($site->handle, Console::FG_YELLOW);
            $this->stdout('":' . PHP_EOL);
            try {
                $mappings = $indexes->source($elementId, $site);
                foreach ($mappings as $field => $source) {
                    $analyzedTokens = $indexes->analyze($source, $site);
                    $analyzedString = '';
                    foreach ($analyzedTokens['tokens'] as $token) {
                        $analyzedString .= $token['token'] . ' ';
                    }
                    $rows[] = [
                        $indexes->mapFieldToAttribute($field),
                        $source,
                        $analyzedString,
                    ];
                }
                echo $table->setRows($rows)->run() . PHP_EOL . PHP_EOL;
            } catch (Missing404Exception) {
                $this->stdout('Element not indexed!' . PHP_EOL, Console::FG_RED);
            }
        }
    }

    /**
     * Deletes the index for all or a specific site.
     *
     * @param string|null $siteHandle The site to delete the index for. Default '*' to delete the index of all sites.
     *
     * @throws SiteNotFoundException|InvalidConfigException
     */
    public function actionDelete(string $siteHandle = null)
    {
        $sites = $this->_getSites($siteHandle);
        $indexes = Elastic::$plugin->getIndexes();

        foreach ($sites as $site) {
            if ($this->orphanedOnly) {
                if (!$this->confirm('Do you want to delete all orphaned indexes for the site with the handle "' . $site->handle . '"?')) {
                    continue;
                }
                $indexes->deleteOrphanedIndexes($site);
            } else {
                if (!$this->confirm('Do you want to delete the index for the site with the handle "' . $site->handle . '"?')) {
                    continue;
                }
                $indexes->deleteIndexOfSite($site);
            }
        }
    }

    /**
     * Reindex the current index for a specific site.
     *
     * @param string|null $siteHandle The site to reindex. Default '*' to reindex the index of all sites.
     *
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public function actionReindex(string $siteHandle = null)
    {
        $indexes = Elastic::$plugin->getIndexes();
        $this->printDriftNotice();
        $sites = $this->_getSites($siteHandle);
        $hint = false;

        foreach ($sites as $site) {
            $currentIndex = $indexes->getCurrentIndex($site);

            if (!$this->confirm('Do you want to reindex the source of the current index "' . $currentIndex . '" for the site with the handle "' . $site->handle . '" to a new index?')) {
                continue;
            }

            if (!$hint) {
                $this->stdout('The process of reindexing can take some time. It depends on many different conditions. Do not interrupt this process and wait until it is finished.' . PHP_EOL);
                $hint = true;
            }

            $this->stdout('Reindexing index for site "');
            $this->stdout($site->handle, Console::FG_YELLOW);
            $this->stdout('":' . PHP_EOL);

            $result = $indexes->reIndexSite($site);

            if ($result === false) {
                $this->stderr('Error when reindexing.', Console::FG_RED);
                return;
            }

            $timeTook = $result['took'] > 1000 ? DateTimeHelper::secondsToHumanTimeDuration((int)round($result['took'] / 1000)) : $result['took'] . 'ms';

            $this->stdout('Old index: ' . $result['oldIndexName'] . PHP_EOL);
            $this->stdout('New index: ' . $result['newIndexName'] . PHP_EOL);
            $this->stdout('Finished after: ' . $timeTook . PHP_EOL);
            $this->stdout('Total of ' . $result['total'] . ' elements migrated.' . PHP_EOL . PHP_EOL);
        }
    }

    /**
     * Clones an existing index as the new index of the given site.
     *
     * @param string $sourceIndexName The name of the source index.
     * @param string $siteHandle The site handle of the destination index.
     *
     * @throws InvalidConfigException
     * @throws SiteNotFoundException
     */
    public function actionClone(string $sourceIndexName, string $siteHandle)
    {
        $indexes = Elastic::$plugin->getIndexes();
        $sites = $this->_getSites($siteHandle);

        foreach ($sites as $site) {
            $sourceExists = $indexes->aliasExists($sourceIndexName);

            if (!$sourceExists) {
                $this->stderr('Source index named "' . $sourceIndexName . '" for site with the handle "' . $site->handle . '" not found.');
                continue;
            }

            $destIndexName = $indexes->getIndexName($site);
            $destExists = $indexes->aliasExists($destIndexName);

            if ($destExists) {
                if (!$this->confirm('The destination index "' . $destIndexName . '" exists. Do you want to replace this index?')) {
                    continue;
                }

                $indexes->deleteIndexOfSite($site);
            }

            $realIndex = $indexes->getIndexOfAlias($sourceIndexName);

            $this->stdout('Cloning index ');
            $this->stdout($sourceIndexName, Console::FG_YELLOW);
            $this->stdout(' (alias of ');
            $this->stdout($realIndex, Console::FG_YELLOW);
            $this->stdout(') to ');
            $this->stdout($destIndexName, Console::FG_YELLOW);
            $this->stdout('...' . PHP_EOL);

            $result = $indexes->cloneToSite($site, $sourceIndexName);

            if (!$result) {
                $this->stderr('Error creating clone.');
            } else {
                $this->stdout('Index cloned.' . PHP_EOL, Console::FG_GREEN);
            }
        }
    }

    /**
     * Exports the current index of a site to an NDJSON file, so it can be recreated on another
     * cluster (e.g. a local one) with elastic/index/import. Runs through the configured
     * connection, so an AWS OpenSearch domain is exported with the usual IAM credentials.
     *
     * Note that the export only carries the Elasticsearch side: searches are filtered against the
     * element IDs the Craft query returns, so the import is only useful together with the
     * matching Craft database.
     *
     * @param string $siteHandle The site whose current index should be exported.
     * @param string|null $file The file to write to. Defaults to <alias>-<timestamp>.ndjson.
     *
     * @return int
     * @throws InvalidConfigException
     */
    public function actionExport(string $siteHandle, string $file = null): int
    {
        $indexes = Elastic::$plugin->getIndexes();

        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$site) {
            $this->stderr('No site found with the handle "' . $siteHandle . '".' . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $file = $file ?? $indexes->getIndexName($site) . '-' . date('Ymd-His') . '.ndjson';

        $this->stdout('Exporting index of site "');
        $this->stdout($site->handle, Console::FG_YELLOW);
        $this->stdout('" to ' . $file . '...' . PHP_EOL);

        $result = $indexes->exportIndex($site, $file);

        $this->stdout('Exported ' . $result['total'] . ' documents from index ' . $result['index'] . '.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Imports an NDJSON file written by elastic/index/export as the new index of a site.
     *
     * The index is recreated exactly as exported, including its mapping version — run
     * elastic/index/reindex afterwards to lift it to the plugin's current schema. The previous
     * index is kept; remove it with "elastic/index/delete --orphaned-only".
     *
     * @param string $file The export file to read.
     * @param string $siteHandle The site to import the index for.
     *
     * @return int
     * @throws InvalidConfigException
     */
    public function actionImport(string $file, string $siteHandle): int
    {
        $indexes = Elastic::$plugin->getIndexes();

        $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
        if (!$site) {
            $this->stderr('No site found with the handle "' . $siteHandle . '".' . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!is_readable($file)) {
            $this->stderr('The file "' . $file . '" does not exist or is not readable.' . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (!$this->confirm('Import "' . $file . '" as the new index for the site with the handle "' . $site->handle . '"?')) {
            return ExitCode::OK;
        }

        $result = $indexes->importIndex($site, $file);

        $this->stdout('Imported ' . $result['total'] . ' documents into ' . $result['index'] . '.' . PHP_EOL, Console::FG_GREEN);
        if ($result['failed'] > 0) {
            $this->stderr($result['failed'] . ' documents were rejected by the cluster, see the logs for details.' . PHP_EOL, Console::FG_YELLOW);
        }
        $this->stdout('The alias ' . $result['alias'] . ' now points to the imported index. Run "php craft elastic/index/reindex" to lift it to the current mapping schema.' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Lists all aliases and indexes from the configured Elasticsearch cluster.
     */
    public function actionList()
    {
        $indexes = Elastic::$plugin->getIndexes();
        $prefix = Elastic::$settings->indexName;
        $result = $indexes->list();
        $table = new Table();

        // Map the drift status by alias so we can annotate each alias row (one cluster call,
        // cached). Returns [] silently if the cluster can't be reached.
        $drift = [];
        foreach ($indexes->detectMappingDrift() as $d) {
            $drift[$d['alias']] = $d;
        }

        $this->stdout('Aliases' . PHP_EOL);
        $table->setHeaders(['Alias', 'Current index', 'Schema']);
        $rows = [];
        $activeIndexes = [];
        foreach ($result['aliases'] as $alias) {
            if (str_starts_with($alias['alias'], '.')) {
                continue;
            }
            if (!$this->all && !str_starts_with($alias['alias'], $prefix)) {
                continue;
            }
            $activeIndexes[] = $alias['index'];
            $rows[] = [
                $alias['alias'],
                $alias['index'],
                $this->schemaCell($drift[$alias['alias']] ?? null),
            ];
        }
        echo $table->setRows($rows)->run() . PHP_EOL;

        $this->stdout('Indexes' . PHP_EOL);
        $header = ['Health', 'Index', 'Status', 'Documents', 'Size'];
        if (!$this->all) {
            $header[] = 'Orphaned';
        }
        $table->setHeaders($header);
        $rows = [];
        foreach ($result['indexes'] as $index) {
            if (str_starts_with($index['index'], '.')) {
                continue;
            }
            if (!$this->all && !str_starts_with($index['index'], $prefix)) {
                continue;
            }
            if (!$this->all) {
                if (!in_array($index['index'], $activeIndexes)) {
                    $index['orphaned'] = 'yes';
                } else {
                    $index['orphaned'] = '';
                }
            }
            $format = match ($index['health']) {
                'red' => [Console::FG_RED],
                'yellow' => [Console::FG_YELLOW],
                'green' => [Console::FG_GREEN],
                default => [Console::FG_GREY],
            };
            $row = [
                Console::ansiFormat($index['health'], $format),
                $index['index'],
                $index['status'],
                $index['docs.count'],
                $index['store.size'],
            ];
            if (!$this->all) {
                $row[] = $index['orphaned'];
            }
            $rows[] = $row;
        }
        echo $table->setRows($rows)->run();
    }

    /**
     * Runs a search query against the primary site's index and outputs the raw Elasticsearch
     * response, including the score explanation (explain: true) for every hit. The query is
     * matched against all indexed Craft content.
     *
     * @param string $query The search string.
     * @param int|null $limit Optional maximum number of results to return.
     *
     * @return int
     * @throws InvalidConfigException
     */
    public function actionQuery(string $query, int $limit = null): int
    {
        $site = Craft::$app->getSites()->getPrimarySite();
        $searchQuery = new SearchQuery($query, Craft::$app->getConfig()->getGeneral()->defaultSearchTermOptions);

        $result = Elastic::$plugin->getElements()->search($searchQuery, [], $site, true, $limit);

        $this->stdout(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Formats a single drift row for the "Schema" column of the alias listing.
     *
     * @param array|null $drift A row from Indexes::detectMappingDrift(), or null if unknown.
     *
     * @return string
     */
    private function schemaCell(?array $drift): string
    {
        if ($drift === null) {
            return '';
        }

        return match ($drift['status']) {
            Indexes::STATUS_CURRENT => Console::ansiFormat('current', [Console::FG_GREEN]),
            Indexes::STATUS_OUTDATED => Console::ansiFormat('outdated (v' . $drift['found'] . '→v' . $drift['expected'] . ') — reindex', [Console::FG_YELLOW]),
            default => Console::ansiFormat('unknown — reindex', [Console::FG_RED]),
        };
    }

    /**
     * Prints a schema-drift notice for every index that is not current, with the exact next
     * step. No-op when everything is current or the cluster can't be reached.
     */
    private function printDriftNotice(): void
    {
        foreach (Elastic::$plugin->getIndexes()->detectMappingDrift() as $drift) {
            if ($drift['status'] === Indexes::STATUS_CURRENT) {
                continue;
            }

            if ($drift['status'] === Indexes::STATUS_OUTDATED) {
                $message = 'Schema drift: index "' . $drift['alias'] . '" runs mapping version v' . $drift['found']
                    . ', the plugin expects v' . $drift['expected'] . '. Reindexing will adopt the current schema.';
            } elseif ($drift['found'] === null) {
                $message = 'Schema drift: index "' . $drift['alias'] . '" has no recorded mapping version '
                    . '(created before drift detection existed). Reindexing will stamp the current schema.';
            } else {
                $message = 'Schema drift: index "' . $drift['alias'] . '" reports mapping version v' . $drift['found']
                    . ', newer than the plugin (v' . $drift['expected'] . ') — the plugin may have been downgraded. '
                    . 'Reindexing will stamp the current schema.';
            }

            $this->stdout($message . PHP_EOL, Console::FG_YELLOW);
        }
    }

    /**
     * Returns the sites as array.
     *
     * @param string|null $siteHandle
     *
     * @return Site[]
     * @throws SiteNotFoundException
     */
    private function _getSites(string $siteHandle = null): array
    {
        if ($siteHandle === null || $siteHandle === '*') {
            $sites = Craft::$app->getSites()->getAllSites();
        } else {
            $site = Craft::$app->getSites()->getSiteByHandle($siteHandle);
            if (!$site) {
                throw new SiteNotFoundException();
            }

            $sites = [$site];
        }

        return $sites;
    }
}
