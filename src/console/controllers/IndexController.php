<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use Craft;
use craft\errors\SiteNotFoundException;
use yii\helpers\BaseConsole;

class IndexController extends Controller
{
    public $defaultAction = 'stats';

    /**
     * @var bool Whether to delete only orphaned indexes and not the current one.
     */
    public bool $orphanedOnly = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'delete') {
            $options[] = 'orphanedOnly';
        }
        return $options;
    }

    /**
     * Outputs some stats of all or a specific indices.
     *
     * @param string|null $siteHandle Default '*' to get stats of all sites.
     *
     * @throws SiteNotFoundException|InvalidConfigException
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
                $this->stdout('Stored data: ' . Craft::$app->getFormatter()->asShortSize($result['indices'][$indexName]['total']['store']['size_in_bytes']) . PHP_EOL);
            } catch (Missing404Exception $e) {
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
     * @param string|null $siteHandle The site to use.
     *
     * @throws SiteNotFoundException|InvalidConfigException
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
        foreach ($sites as $site) {
            $this->stdout('Index source of element "');
            $this->stdout($element, BaseConsole::FG_YELLOW);
            $this->stdout('" for site "');
            $this->stdout($site->handle, BaseConsole::FG_YELLOW);
            $this->stdout('":' . PHP_EOL);
            try {
                $mappings = Elastic::$plugin->getIndexes()->source($elementId, $site);
                foreach ($mappings as $field => $mapping) {
                    $this->stdout($indexService->mapFieldToAttribute($field) . ': ' . $mapping . PHP_EOL);
                }
            } catch (Missing404Exception $e) {
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

            $timeTook = $result['took'] > 1000 ? DateTimeHelper::secondsToHumanTimeDuration(round($result['took']/1000)) : $result['took'] . 'ms';

            $this->stdout('Old index: ' . $result['oldIndexName'] . PHP_EOL);
            $this->stdout('New index: ' . $result['newIndexName'] . PHP_EOL);
            $this->stdout('Finished after: ' . $timeTook . PHP_EOL);
            $this->stdout('Total of ' . $result['total'] . ' elements migrated.' . PHP_EOL);
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

            $result = $indexService->cloneToSite($site, $sourceIndexName);

            if (!$result) {
                $this->stderr('Error creating clone.');
            }
        }
    }

    /**
     * Returns the sites as array.
     *
     * @param string|null $siteHandle
     *
     * @return array|Site[]
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