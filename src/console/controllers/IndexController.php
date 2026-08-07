<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\services\Indexes;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use craft\search\SearchQuery;
use yii\console\ExitCode;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use Craft;
use craft\errors\SiteNotFoundException;
use yii\console\widgets\Table;
use yii\helpers\BaseConsole;

class IndexController extends Controller
{
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
                $this->stdout($site->handle, BaseConsole::FG_YELLOW);
                $this->stdout('":' . PHP_EOL);
                $this->stdout('Current index in use: ' . $indexName . PHP_EOL);
                $this->stdout('Elements in index: ' . $result['indices'][$indexName]['total']['docs']['count'] . PHP_EOL);
                $this->stdout('Stored data: ' . Craft::$app->getFormatter()->asShortSize($result['indices'][$indexName]['total']['store']['size_in_bytes']) . PHP_EOL . PHP_EOL);
            } catch (Missing404Exception) {
                $this->stderr('Index for site "');
                $this->stderr($site->handle, BaseConsole::FG_YELLOW);
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
            $this->stderr("Element with ID $elementId not found!" . PHP_EOL, BaseConsole::FG_RED);
            return;
        }

        $indexService = Elastic::$plugin->getIndexes();
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
            $this->stdout($element, BaseConsole::FG_YELLOW);
            $this->stdout('" for site "');
            $this->stdout($site->handle, BaseConsole::FG_YELLOW);
            $this->stdout('":' . PHP_EOL);
            try {
                $mappings = Elastic::$plugin->getIndexes()->source($elementId, $site);
                foreach ($mappings as $field => $source) {
                    $analyzedTokens = $indexService->analyze($source, $site);
                    $analyzedString = '';
                    foreach ($analyzedTokens['tokens'] as $token) {
                        $analyzedString .= $token['token'] . ' ';
                    }
                    $rows[] = [
                        $indexService->mapFieldToAttribute($field),
                        $source,
                        $analyzedString,
                    ];
                }
                echo $table->setRows($rows)->run() . PHP_EOL . PHP_EOL;
            } catch (Missing404Exception) {
                $this->stdout('Element not indexed!' . PHP_EOL, BaseConsole::FG_RED);
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
        $indexService = Elastic::$plugin->getIndexes();
        $this->printDriftNotice();
        $sites = $this->_getSites($siteHandle);
        $hint = false;

        foreach ($sites as $site) {

            $currentIndex = $indexService->getCurrentIndex($site);

            if (!$this->confirm('Do you want to reindex the source of the current index "' . $currentIndex . '" for the site with the handle "' . $site->handle . '" to a new index?')) {
                continue;
            }

            if (!$hint) {
                $this->stdout('The process of reindexing can take some time. It depends on many different conditions. Do not interrupt this process and wait until it is finished.' . PHP_EOL);
                $hint = true;
            }

            $this->stdout('Reindexing index for site "');
            $this->stdout($site->handle, BaseConsole::FG_YELLOW);
            $this->stdout('":' . PHP_EOL);

            $result = Elastic::$plugin->getIndexes()->reIndexSite($site);

            if ($result === false) {
                $this->stderr('Error when reindexing.', BaseConsole::FG_RED);
                return;
            }

            $timeTook = $result['took'] > 1000 ? DateTimeHelper::secondsToHumanTimeDuration(round($result['took'] / 1000)) : $result['took'] . 'ms';

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
        $indexService = Elastic::$plugin->getIndexes();
        $sites = $this->_getSites($siteHandle);

        foreach ($sites as $site) {

            $sourceExists = $indexService->aliasExists($sourceIndexName);

            if (!$sourceExists) {
                $this->stderr('Source index named "' . $sourceIndexName . '" for site with the handle "' . $site->handle . '" not found.');
                continue;
            }

            $destIndexName = $indexService->getIndexName($site);
            $destExists = $indexService->aliasExists($destIndexName);

            if ($destExists) {
                if (!$this->confirm('The destination index "' . $destIndexName . '" exists. Do you want to replace this index?')) {
                    continue;
                }

                $indexService->deleteIndexOfSite($site);
            }

            $realIndex = $indexService->getIndexOfAlias($sourceIndexName);

            $this->stdout('Cloning index ');
            $this->stdout($sourceIndexName, BaseConsole::FG_YELLOW);
            $this->stdout(' (alias of ');
            $this->stdout($realIndex, BaseConsole::FG_YELLOW);
            $this->stdout(') to ');
            $this->stdout($destIndexName, BaseConsole::FG_YELLOW);
            $this->stdout('...' . PHP_EOL);

            $result = $indexService->cloneToSite($site, $sourceIndexName);

            if (!$result) {
                $this->stderr('Error creating clone.');
            } else {
                $this->stdout('Index cloned.' . PHP_EOL, Console::FG_GREEN);
            }
        }
    }

    /**
     * Lists all aliases and indexes from the configured Elasticsearch cluster.
     */
    public function actionList()
    {
        $indexService = Elastic::$plugin->getIndexes();
        $prefix = Elastic::$settings->indexName;
        $result = $indexService->list();
        $table = new Table();

        // Map the drift status by alias so we can annotate each alias row (one cluster call,
        // cached). Returns [] silently if the cluster can't be reached.
        $drift = [];
        foreach ($indexService->detectMappingDrift() as $d) {
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

            $this->stdout($message . PHP_EOL, BaseConsole::FG_YELLOW);
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