<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
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

        $this->stdout('Aliases' . PHP_EOL);
        $table->setHeaders(['Alias', 'Current index']);
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