<?php

namespace codemonauts\elastic\services;

use codemonauts\elastic\Elastic;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\models\Site;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use Exception;
use RuntimeException;
use stdClass;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Index handling
 */
class Indexes extends Component
{
    /**
     * @var string Index schema is in sync with the plugin's current mapping version.
     */
    public const STATUS_CURRENT = 'current';

    /**
     * @var string Index was created with an older mapping version; a reindex is needed.
     */
    public const STATUS_OUTDATED = 'outdated';

    /**
     * @var string Version could not be determined (index predates this feature, or the
     *             plugin was downgraded below the index's version).
     */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * @var int The mapping version from which indexes carry the catch-all field. Documents in
     *          older indexes have no catch-all content — adding the field to their mapping does
     *          not backfill them — so those indexes keep being queried field by field until they
     *          are rebuilt.
     */
    public const CATCH_ALL_MAPPING_VERSION = 4;

    /**
     * @var string Marker written into the header line of an export file.
     */
    public const EXPORT_TYPE = 'codemonauts/elastic index export';

    /**
     * @var int Format version of the export file, so a future reader can detect old dumps.
     */
    public const EXPORT_FORMAT_VERSION = 1;

    /**
     * @var string How long the cluster keeps a scroll context alive between batches.
     */
    private const SCROLL_TTL = '2m';

    /**
     * @var string[] Index settings the cluster reports back but refuses on index creation, so
     *               they have to be stripped before an exported index can be recreated.
     */
    private const NON_CREATABLE_SETTINGS = ['uuid', 'creation_date', 'version', 'provided_name', 'resize', 'history'];

    /**
     * @var string The name to use for the indexes.
     */
    public string $indexName;

    /**
     * @var string[] Mapping of Elasticsearch field names to Craft field handles.
     */
    private array $fieldToAttribute = [];


    // Functions to create and delete index structure for Craft's search
    // =========================================================================


    /**
     * Check if alias exists and create alias and index if not.
     *
     * @param Site $site The site to check the alias for.
     *
     * @throws InvalidConfigException
     */
    public function ensureIndexForSiteExists(Site $site)
    {
        if (!$this->aliasExists($this->getIndexName($site))) {
            $this->createIndexForSite($site);
        }
    }

    /**
     * Creates the alias and index for a site.
     *
     * @param Site $site The site to create the alias and index for.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function createIndexForSite(Site $site): bool
    {
        $result = $this->createIndex($site);

        return $this->addAlias($result['index'], $site);
    }

    /**
     * Delete all site's indexes and the alias.
     *
     * @param Site $site The site to delete the index for.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function deleteIndexOfSite(Site $site): array
    {
        $deletedIndexes = [];

        $params = [
            'index' => $this->getIndexName($site) . '_*',
        ];

        $indexes = Elastic::$plugin->getElasticsearch()->getClient()->indices()->get($params);

        foreach ($indexes as $indexName => $indexDetails) {
            foreach (($indexDetails['aliases'] ?? []) as $aliasName => $aliasDetails) {
                $this->deleteAlias($indexName, $aliasName);
            }

            $this->deleteIndex($indexName);
            $deletedIndexes[] = $indexName;
        }

        return $deletedIndexes;
    }

    /**
     * Reindex the current index of a site to a new version with current mapping.
     *
     * @param Site $site The site to reindex the index for.
     *
     * @return array|false
     * @throws InvalidConfigException
     */
    public function reIndexSite(Site $site): bool|array
    {
        $returnValue = [];

        // Create new index based on the current settings
        $result = $this->createIndex($site);
        $newIndexName = $result['index'];
        $returnValue['newIndexName'] = $newIndexName;

        // Get current index
        $oldIndexName = $this->getCurrentIndex($site);
        $returnValue['oldIndexName'] = $oldIndexName;

        $params = [
            'body' => [
                'source' => [
                    'index' => $oldIndexName,
                    '_source' => array_keys($this->buildMapping()),
                ],
                'dest' => [
                    'index' => $newIndexName,
                ],
            ],
        ];

        $result = Elastic::$plugin->getElasticsearch()->getClient()->reindex($params);

        if (count($result['failures'] ?? []) > 0) {
            return false;
        }

        $returnValue['total'] = $result['total'] ?? 0;
        $returnValue['took'] = $result['took'];
        if ($this->addAlias($newIndexName, $site)) {
            $this->deleteAlias($oldIndexName, $this->getIndexName($site));
        }

        return $returnValue;
    }

    /**
     * Clones an existing index as a new index for a site.
     *
     * @param Site $site The destination site for the new index.
     * @param string $sourceAlias The full name of the source index to use for cloning.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function cloneToSite(Site $site, string $sourceAlias): bool
    {
        $sourceIndex = $this->getIndexOfAlias($sourceAlias);

        $this->setIndexToReadOnly($sourceIndex);

        $targetAlias = $this->getIndexName($site);
        $targetIndex = $targetAlias . '_' . time();

        $params = [
            'index' => $sourceIndex,
            'target' => $targetIndex,
            'body' => [
                'aliases' => [
                    $targetAlias => [
                        'is_write_index' => true,
                    ],
                ],
            ],
        ];

        try {
            $result = Elastic::$plugin->getElasticsearch()->getClient()->indices()->clone($params);
        } catch (Exception) {
            $result = ['acknowledged' => false];
        }

        $this->setIndexToWrite($sourceIndex);

        return (bool)$result['acknowledged'];
    }



    // Functions to export and import indexes
    // =========================================================================


    /**
     * Exports the current index of a site to an NDJSON file.
     *
     * The first line is a header holding the index metadata (mappings, settings and the source
     * alias/index); every following line is one document as {"_id": ..., "_source": {...}}. The
     * file is written as a stream, so its size is not bound by available memory.
     *
     * Note that the export only carries the Elasticsearch side. Since searches are filtered
     * against the element IDs the Craft query returns, an imported index is only useful together
     * with the matching Craft database.
     *
     * @param Site $site The site whose current index should be exported.
     * @param string $path The file to write to.
     * @param int $batchSize How many documents to fetch per scroll request.
     *
     * @return array{alias: string, index: string, total: int, file: string}
     * @throws InvalidConfigException
     */
    public function exportIndex(Site $site, string $path, int $batchSize = 1000): array
    {
        $client = Elastic::$plugin->getElasticsearch()->getClient();
        $alias = $this->getIndexName($site);
        $index = (string)$this->getCurrentIndex($site);

        $mappings = $client->indices()->getMapping(['index' => $index])[$index]['mappings'] ?? [];
        $settings = $client->indices()->getSettings(['index' => $index])[$index]['settings']['index'] ?? [];

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not open "' . $path . '" for writing.');
        }

        $total = 0;
        $scrollId = null;

        try {
            $this->writeJsonLine($handle, [
                'type' => self::EXPORT_TYPE,
                'formatVersion' => self::EXPORT_FORMAT_VERSION,
                'alias' => $alias,
                'index' => $index,
                'mappings' => $mappings,
                'settings' => array_diff_key($settings, array_flip(self::NON_CREATABLE_SETTINGS)),
            ]);

            $response = $client->search([
                'index' => $index,
                'scroll' => self::SCROLL_TTL,
                'size' => $batchSize,
                'body' => [
                    'query' => [
                        'match_all' => new stdClass(),
                    ],
                ],
            ]);

            while (count($response['hits']['hits'] ?? []) > 0) {
                foreach ($response['hits']['hits'] as $hit) {
                    $this->writeJsonLine($handle, [
                        '_id' => $hit['_id'],
                        '_source' => $hit['_source'] ?? new stdClass(),
                    ]);
                    $total++;
                }

                $scrollId = $response['_scroll_id'] ?? $scrollId;
                if ($scrollId === null) {
                    break;
                }

                $response = $client->scroll([
                    'scroll_id' => $scrollId,
                    'scroll' => self::SCROLL_TTL,
                ]);
            }
        } finally {
            fclose($handle);

            if ($scrollId !== null) {
                try {
                    // Release the scroll context instead of waiting for it to time out.
                    $client->clearScroll(['scroll_id' => $scrollId]);
                } catch (Throwable $e) {
                    Craft::warning('Could not clear the Elasticsearch scroll context: ' . $e->getMessage(), 'elastic');
                }
            }
        }

        return [
            'alias' => $alias,
            'index' => $index,
            'total' => $total,
            'file' => $path,
        ];
    }

    /**
     * Imports an NDJSON file written by exportIndex() as the new index of a site.
     *
     * The index is recreated from the mappings and settings stored in the file — so the export
     * is reproduced faithfully, including its mapping version. Run elastic/index/reindex
     * afterwards to lift it to the plugin's current schema. On success the site alias is moved
     * to the new index and the previous one is left in place (delete it with
     * elastic/index/delete --orphaned-only).
     *
     * @param Site $site The site to import the index for.
     * @param string $path The export file to read.
     * @param int $batchSize How many documents to send per bulk request.
     *
     * @return array{alias: string, index: string, sourceIndex: string, total: int, failed: int}
     * @throws InvalidConfigException
     */
    public function importIndex(Site $site, string $path, int $batchSize = 1000): array
    {
        $client = Elastic::$plugin->getElasticsearch()->getClient();

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open "' . $path . '" for reading.');
        }

        $alias = $this->getIndexName($site);
        $newIndex = $alias . '_' . time();
        $total = 0;
        $failed = 0;

        try {
            $header = $this->readJsonLine($handle);
            if (($header['type'] ?? null) !== self::EXPORT_TYPE) {
                throw new RuntimeException('"' . $path . '" is not an Elasticsearch index export.');
            }

            $client->indices()->create([
                'index' => $newIndex,
                'body' => [
                    'settings' => $header['settings'] ?? new stdClass(),
                    'mappings' => $header['mappings'] ?? new stdClass(),
                ],
            ]);

            $batch = [];
            while (($document = $this->readJsonLine($handle)) !== null) {
                $batch[] = $document;

                if (count($batch) >= $batchSize) {
                    $failed += $this->bulkIndex($newIndex, $batch);
                    $total += count($batch);
                    $batch = [];
                }
            }

            if (count($batch) > 0) {
                $failed += $this->bulkIndex($newIndex, $batch);
                $total += count($batch);
            }
        } finally {
            fclose($handle);
        }

        // Make the documents visible to searches right away instead of waiting for the refresh
        // interval — an import is usually followed by a query.
        $client->indices()->refresh(['index' => $newIndex]);

        $oldIndex = $this->aliasExists($alias) ? (string)$this->getCurrentIndex($site) : null;
        if ($this->addAlias($newIndex, $site) && $oldIndex !== null) {
            $this->deleteAlias($oldIndex, $alias);
        }

        return [
            'alias' => $alias,
            'index' => $newIndex,
            'sourceIndex' => (string)($header['index'] ?? ''),
            'total' => $total,
            'failed' => $failed,
        ];
    }

    /**
     * Indexes a batch of exported documents, keeping their original IDs.
     *
     * @param string $indexName The index to write to.
     * @param array $documents Rows of ['_id' => ..., '_source' => [...]].
     *
     * @return int The number of documents the cluster rejected.
     * @throws InvalidConfigException
     */
    private function bulkIndex(string $indexName, array $documents): int
    {
        $params = ['body' => []];

        foreach ($documents as $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => $document['_id'],
                ],
            ];
            $params['body'][] = $document['_source'] ?? new stdClass();
        }

        $result = Elastic::$plugin->getElasticsearch()->getClient()->bulk($params);

        if (!($result['errors'] ?? false)) {
            return 0;
        }

        $failed = 0;
        foreach ($result['items'] ?? [] as $item) {
            if (isset($item['index']['error'])) {
                $failed++;
                Craft::warning('Could not import document ' . ($item['index']['_id'] ?? '?') . ': ' . json_encode($item['index']['error']), 'elastic');
            }
        }

        return $failed;
    }

    /**
     * Writes one JSON line to an open export file.
     *
     * @param resource $handle
     * @param array $data
     */
    private function writeJsonLine($handle, array $data): void
    {
        fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    /**
     * Reads the next non-empty JSON line of an export file.
     *
     * @param resource $handle
     *
     * @return array|null The decoded line, or null at the end of the file.
     */
    private function readJsonLine($handle): ?array
    {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Malformed line in the export file: ' . substr($line, 0, 80));
            }

            return $decoded;
        }

        return null;
    }



    // Functions to create, update and delete aliases
    // =========================================================================


    /**
     * Adds an alias to a given index name.
     *
     * @param string $indexName The index name to create the alias for.
     * @param Site $site The site to create the alias for.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function addAlias(string $indexName, Site $site): bool
    {
        $params = [
            'index' => $indexName,
            'name' => $this->getIndexName($site),
        ];

        $result = Elastic::$plugin->getElasticsearch()->getClient()->indices()->putAlias($params);

        Craft::$app->cache->delete($this->driftCacheKey());

        return (bool)$result['acknowledged'];
    }

    /**
     * Deletes an alias for a given index.
     *
     * @param string $indexName The index name to delete the alias for.
     * @param string $aliasName The alias name to delete.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function deleteAlias(string $indexName, string $aliasName): array
    {
        $params = [
            'index' => $indexName,
            'name' => $aliasName,
        ];

        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->deleteAlias($params);
    }

    /**
     * Returns whether the given alias exists.
     *
     * @param string $aliasName The name of the alias to check.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function aliasExists(string $aliasName): bool
    {
        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->existsAlias([
            'name' => $aliasName,
        ]);
    }



    // Functions to create, update and delete indexes
    // =========================================================================


    /**
     * Generate the index name.
     *
     * @param Site $site The site to return the index name for.
     * @param string|null $indexName Overwrite index name from settings.
     *
     * @return string
     */
    public function getIndexName(Site $site, string $indexName = null): string
    {
        if ($indexName === null) {
            $indexName = $this->indexName;
        }

        return strtolower($indexName . '_' . $site->handle);
    }

    /**
     * Creates Elasticsearch's index for a given site.
     *
     * @param Site $site The site the index should be created for.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function createIndex(Site $site): array
    {
        $config = $this->buildIndexConfiguration($site);

        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->create($config);
    }

    /**
     * Returns the index configuration for a site.
     *
     * @param Site $site
     *
     * @return array
     */
    public function buildIndexConfiguration(Site $site): array
    {
        return [
            'index' => $this->getIndexName($site) . '_' . time(),
            'body' => [
                'settings' => $this->buildSettings($site),
                'mappings' => [
                    // Stamp the schema version into the mapping metadata. `_meta` is ignored
                    // by the cluster and read back by detectMappingDrift(). Never in properties.
                    '_meta' => [
                        'cmonauts_mapping_version' => Elastic::MAPPING_VERSION,
                    ],
                    'properties' => $this->buildMapping(),
                ],
            ],
        ];
    }

    /**
     * Returns the name of the current used index.
     *
     * @param Site $site The site to return the index name for.
     *
     * @return int|string
     * @throws InvalidConfigException
     */
    public function getCurrentIndex(Site $site): int|string
    {
        return $this->getIndexOfAlias($this->getIndexName($site));
    }

    /**
     * Returns the current index of an alias.
     *
     * @param string $aliasName The name of the alias.
     *
     * @return int|string
     * @throws InvalidConfigException
     */
    public function getIndexOfAlias(string $aliasName): int|string
    {
        $params = [
            'name' => $aliasName,
        ];

        $result = Elastic::$plugin->getElasticsearch()->getClient()->indices()->getAlias($params);

        return array_keys($result)[0] ?? '';
    }

    /**
     * Deletes an index by its name.
     *
     * @param string $indexName The name of the index to delete.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function deleteIndex(string $indexName): array
    {
        $params = [
            'index' => $indexName,
        ];

        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->delete($params);
    }

    /**
     * Deletes all orphaned Elasticsearch indexes.
     *
     * @param Site $site The site to delete the orphaned indexes from.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function deleteOrphanedIndexes(Site $site): array
    {
        $deletedIndexes = [];

        $params = [
            'index' => $this->getIndexName($site) . '_*',
        ];

        $indexes = Elastic::$plugin->getElasticsearch()->getClient()->indices()->get($params);

        $currentIndex = $this->getCurrentIndex($site);

        foreach ($indexes as $indexName => $indexDetails) {
            if ($currentIndex === $indexName) {
                continue;
            }
            $this->deleteIndex($indexName);
            $deletedIndexes[] = $indexName;
        }

        return $deletedIndexes;
    }

    /**
     * Disables writing to the given index.
     *
     * @param string $indexName The name of the indes to disable writing.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function setIndexToReadOnly(string $indexName): bool
    {
        return $this->setIndexBlockWrite($indexName, true);
    }

    /**
     * Enables writing to the given index.
     *
     * @param string $indexName The name of the index to enable writing.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function setIndexToWrite(string $indexName): bool
    {
        return $this->setIndexBlockWrite($indexName, false);
    }

    /**
     * Sets write blocking status of an index.
     *
     * @param string $indexName The name of the index to set write blocking status.
     * @param bool $status Whether to block all write requests to the given index.
     *
     * @return bool
     * @throws InvalidConfigException
     */
    public function setIndexBlockWrite(string $indexName, bool $status): bool
    {
        $params = [
            'index' => $indexName,
            'body' => [
                'settings' => [
                    'index.blocks.write' => $status,
                ],
            ],
        ];

        $result = Elastic::$plugin->getElasticsearch()->getClient()->indices()->putSettings($params);

        return (bool)$result['acknowledged'];
    }



    // Functions for mapping fields
    // =========================================================================


    /**
     * Returns the current mapping of the index for a site.
     *
     * @param Site $site The site to return the current mapping for.
     *
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     */
    public function getCurrentMapping(Site $site): mixed
    {
        $currentIndex = $this->getCurrentIndex($site);
        $result = Elastic::$plugin->getElasticsearch()->getClient()->indices()->getMapping(['index' => $currentIndex]);

        return $result[$currentIndex]['mappings']['properties'] ?? [];
    }

    /**
     * Detects mapping-schema drift for every site's index.
     *
     * For each site alias it resolves the concrete index, reads the stamped
     * cmonauts_mapping_version from the mapping's _meta and compares it to Elastic::MAPPING_VERSION.
     * Returns one row per resolved index:
     *   ['alias' => string, 'site' => string|null, 'index' => string, 'found' => int|null,
     *    'expected' => int, 'status' => self::STATUS_CURRENT|STATUS_OUTDATED|STATUS_UNKNOWN]
     *
     * Read-only — never mutates the cluster. The result is cached (key includes the plugin
     * version, so an update invalidates it) with a short TTL: a cache hit costs no cluster
     * call, a miss costs exactly one. If the cluster is unreachable it logs and returns an
     * empty array so the site keeps working; it never throws into the request cycle.
     *
     * @return array<int, array{alias: string, site: string|null, index: string, found: int|null, expected: int, status: string}>
     */
    public function detectMappingDrift(): array
    {
        $cacheKey = $this->driftCacheKey();
        $cached = Craft::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $expected = Elastic::MAPPING_VERSION;

        // Map each site alias to its site handle so the result can name the exact reindex
        // command (--site-handle takes the Craft site handle, not the alias).
        $aliasToSite = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $aliasToSite[$this->getIndexName($site)] = $site->handle;
        }
        $aliases = array_keys($aliasToSite);
        if (count($aliases) === 0) {
            return [];
        }

        try {
            // One cluster call for all aliases. Each alias resolves to its current concrete
            // index; the response is keyed by concrete index name. ignore_unavailable keeps
            // it from throwing when an alias has no index yet.
            $result = Elastic::$plugin->getElasticsearch()->getClient()->indices()->getMapping([
                'index' => implode(',', $aliases),
                'ignore_unavailable' => true,
                'allow_no_indices' => true,
            ]);
        } catch (Throwable $e) {
            // Cluster unreachable or any other error: stay silent to the request cycle, log
            // for the operator, and return nothing (not cached, so it retries next time).
            Craft::warning('Could not read mapping metadata for drift detection: ' . $e->getMessage(), 'elastic');

            return [];
        }

        $rows = [];
        foreach ($result as $concreteIndex => $data) {
            // Associate the concrete index back to its alias via the naming convention
            // (concrete index = alias . '_' . time(), see buildIndexConfiguration()).
            $alias = $concreteIndex;
            foreach ($aliases as $candidate) {
                if (str_starts_with($concreteIndex, $candidate . '_')) {
                    $alias = $candidate;
                    break;
                }
            }

            $found = $data['mappings']['_meta']['cmonauts_mapping_version'] ?? null;
            $found = is_numeric($found) ? (int)$found : null;

            if ($found === null) {
                // No stamp: index predates this feature.
                $status = self::STATUS_UNKNOWN;
            } elseif ($found === $expected) {
                $status = self::STATUS_CURRENT;
            } elseif ($found < $expected) {
                $status = self::STATUS_OUTDATED;
            } else {
                // found > expected: plugin was downgraded below the index's version.
                $status = self::STATUS_UNKNOWN;
            }

            $rows[] = [
                'alias' => $alias,
                'site' => $aliasToSite[$alias] ?? null,
                'index' => $concreteIndex,
                'found' => $found,
                'expected' => $expected,
                'status' => $status,
            ];
        }

        Craft::$app->cache->set($cacheKey, $rows, 300);

        return $rows;
    }

    /**
     * Cache key for the drift detection result. Includes the plugin version so a plugin
     * update automatically invalidates any previously cached result.
     *
     * @return string
     */
    private function driftCacheKey(): string
    {
        return 'elastic:mappingdrift:' . Elastic::$plugin->version;
    }

    /**
     * The name of the catch-all field every text field copies into.
     *
     * @return string
     */
    public function catchAllField(): string
    {
        return Elastic::$settings->fieldPrefix . 'all';
    }

    /**
     * The name of the keyword catch-all field used for exact, whole-value matching.
     *
     * Copying into a keyword field keeps every source value as its own term instead of
     * concatenating them, so a term query against it still means "some field holds exactly this
     * value" — the same semantics as matching the per-field ".exact" subfields, in one clause.
     *
     * @return string
     */
    public function catchAllExactField(): string
    {
        return Elastic::$settings->fieldPrefix . 'all_exact';
    }

    /**
     * Whether the site's current index can be queried through the catch-all field.
     *
     * True only once the index was rebuilt at CATCH_ALL_MAPPING_VERSION or later. Adding the
     * field to an existing mapping succeeds, but leaves already-indexed documents without any
     * catch-all content — querying it there would silently return nothing. The stamped version
     * in the mapping's `_meta` only changes when the plugin creates the index, so it is a
     * reliable signal (a plain mapping update leaves it untouched).
     *
     * @param Site $site The site whose current index to check.
     *
     * @return bool
     */
    public function supportsCatchAll(Site $site): bool
    {
        $cacheKey = 'elastic:catchall:' . $site->id . ':' . Elastic::$plugin->version;
        $cached = Craft::$app->cache->get($cacheKey);
        if ($cached !== false) {
            return (bool)$cached;
        }

        $supported = false;
        try {
            $index = (string)$this->getCurrentIndex($site);
            $mappings = Elastic::$plugin->getElasticsearch()->getClient()->indices()->getMapping(['index' => $index]);
            $found = $mappings[$index]['mappings']['_meta']['cmonauts_mapping_version'] ?? null;
            $supported = is_numeric($found) && (int)$found >= self::CATCH_ALL_MAPPING_VERSION;
        } catch (Throwable $e) {
            // Unreachable cluster or missing index: fall back to the field-by-field query, which
            // works on every index generation.
            Craft::warning('Could not determine the mapping version for the catch-all field: ' . $e->getMessage(), 'elastic');
        }

        Craft::$app->cache->set($cacheKey, $supported ? 1 : 0, 300);

        return $supported;
    }

    /**
     * Returns the mapping configuration for a site.
     *
     * @return array
     */
    public function buildMapping(): array
    {
        $fieldPrefix = Elastic::$settings->fieldPrefix;
        $mapping = [];

        // Catch-all fields every text field copies into. Querying these instead of a wildcard over
        // every field keeps the clause count constant: Elasticsearch expands `*` to one clause per
        // field (two, counting the ".exact" subfields), so a wildcard query grows with the number
        // of searchable fields and eventually trips Lucene's maxClauseCount (1024). The copied
        // content only lives in the index, not in _source.
        $mapping[$this->catchAllField()] = [
            'type' => 'text',
        ];
        $mapping[$this->catchAllExactField()] = [
            'type' => 'keyword',
            'normalizer' => 'lowercase_normalizer',
            'ignore_above' => 256,
        ];

        // Every searchable field is a text field with an additive ".exact" keyword subfield.
        // The subfield enables exact, whole-value matching (used for scoring and title::exact
        // queries); "ignore_above" drops values longer than 256 chars, so it stays cheap even
        // on large body-text fields. Adding a subfield is legal against existing indexes, but
        // existing documents only populate it after a rebuild.
        $textField = [
            'type' => 'text',
            'copy_to' => [$this->catchAllField(), $this->catchAllExactField()],
            'fields' => [
                'exact' => [
                    'type' => 'keyword',
                    'normalizer' => 'lowercase_normalizer',
                    'ignore_above' => 256,
                ],
            ],
        ];

        $predefinedAttributes = [
            'title',
            'slug',
        ];

        foreach ($predefinedAttributes as $attribute) {
            $mapping[$fieldPrefix . 'attribute_' . $attribute] = $textField;
        }

        /**
         * @var ElementInterface $elementType
         */
        $elementTypes = Craft::$app->elements->getAllElementTypes();
        foreach ($elementTypes as $elementType) {
            foreach ($elementType::searchableAttributes() as $attribute) {
                $mapping[$fieldPrefix . 'attribute_' . $attribute] = $textField;
            }
        }

        $fields = Craft::$app->getFields()->getAllFields();
        foreach ($fields as $field) {
            if ($field->searchable) {
                $mapping[$fieldPrefix . 'field_' . $field->id] = $textField;
            }
        }

        return $mapping;
    }

    /**
     * Returns the index settings for a site, including the language-aware default analyzer.
     *
     * @param Site $site The site to build the settings for.
     *
     * @return array
     */
    public function buildSettings(Site $site): array
    {
        $language = $this->getStopWord($site);

        return [
            "analysis" => [
                "analyzer" => [
                    // Define the analyzer in the reserved "default" slot so every text field
                    // without an explicit analyzer falls back to it. This applies the
                    // language-aware stopword handling everywhere and lets field mappings omit
                    // the analyzer parameter entirely (which is what avoids the 400 conflict).
                    "default" => [
                        "type" => "standard",
                        "stopwords" => $language,
                    ],
                ],
                "normalizer" => [
                    // Lowercasing normalizer for the keyword ".exact" subfields, so an exact
                    // match is case-insensitive (e.g. "Abba" matches "abba"). There is no
                    // built-in normalizer named "lowercase", so it must be defined here.
                    "lowercase_normalizer" => [
                        "type" => "custom",
                        "filter" => ["lowercase"],
                    ],
                ],
            ],
        ];
    }

    /**
     * Updates the mapping on an existing index for a site.
     *
     * @param Site $site
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function updateMapping(Site $site): array
    {
        $mapping = $this->buildMapping();

        $params = [
            'index' => $this->getIndexName($site),
            'body' => [
                'properties' => $mapping,
            ],
        ];

        Craft::$app->cache->set('elastic:mapping:' . $site->id, array_keys($mapping));

        try {
            return Elastic::$plugin->getElasticsearch()->getClient()->indices()->putMapping($params);
        } catch (BadRequest400Exception $e) {
            // The cluster rejects mapping changes that aren't possible on an existing index
            // (e.g. changing a field's analyzer) with HTTP 400 illegal_argument_exception.
            // This is a schema conflict, not a transient error: surface it with a clear next
            // step and keep the original cluster message in the exception chain.
            $message = 'Elasticsearch rejected the mapping update for index "' . $this->getIndexName($site)
                . '" (schema conflict). The existing index cannot be updated in place; reindex it '
                . 'with "php craft elastic/index/reindex --site-handle=' . $site->handle . '".';
            Craft::error($message . ' Cluster said: ' . $e->getMessage(), 'elastic');

            throw new Exception($message, 0, $e);
        }
    }

    /**
     * Checks if the current mapping of the index is in sync with the current field and element types.
     *
     * @param array $mapping The list of fields and attributes to check against.
     * @param Site $site The site of the index to check.
     *
     * @throws InvalidConfigException
     */
    public function ensureMappingInSync(array $mapping, Site $site)
    {
        $currentMapping = Craft::$app->cache->get('elastic:mapping:' . $site->id);
        if (!$currentMapping) {
            $currentMapping = $this->getCurrentMapping($site);
        }

        $diff = array_diff($currentMapping, $mapping);

        if (count($diff) > 0) {
            $this->updateMapping($site);
        }
    }

    /**
     * Returns the corresponding Elasticsearch index field name for an attribute name or field handle.
     *
     * @param string $attribute The attribute name or field handle.
     *
     * @return string
     */
    public function mapAttributeToField(string $attribute): string
    {
        $fieldPrefix = Elastic::$settings->fieldPrefix;

        $field = Craft::$app->fields->getFieldByHandle($attribute);
        if (!$field) {
            return $fieldPrefix . 'attribute_' . $attribute;
        }

        return $fieldPrefix . 'field_' . $field->id;
    }

    /**
     * Returns the corresponding attribute or field handle for an Elasticsearch index field name.
     *
     * @param string $fieldName The Elasticsearch field name.
     *
     * @return string
     */
    public function mapFieldToAttribute(string $fieldName): string
    {
        if (!isset($this->fieldToAttribute[$fieldName])) {
            $fieldPrefix = Elastic::$settings->fieldPrefix;
            $attributeNeedle = $fieldPrefix . 'attribute_';
            $fieldNeedle = $fieldPrefix . 'field_';
            if (str_starts_with($fieldName, $attributeNeedle)) {
                $this->fieldToAttribute[$fieldName] = substr($fieldName, strlen($attributeNeedle));
            } elseif (str_starts_with($fieldName, $fieldNeedle)) {
                $id = (int)substr($fieldName, strlen($fieldNeedle));
                $field = Craft::$app->getFields()->getFieldById($id);
                if (!$field) {
                    $this->fieldToAttribute[$fieldName] = 'field not found for ID ' . $id;
                } else {
                    $this->fieldToAttribute[$fieldName] = $field->handle;
                }
            } else {
                $this->fieldToAttribute[$fieldName] = 'Unknown field ' . $fieldName;
            }
        }

        return $this->fieldToAttribute[$fieldName];
    }



    // Functions for statistics and source
    // =========================================================================


    /**
     * Get stats od an index.
     *
     * @param Site $site
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function stats(Site $site): array
    {
        $currentIndex = $this->getCurrentIndex($site);

        $params = [
            'index' => $currentIndex,
        ];

        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->stats($params);
    }

    /**
     * Get the source of an index entry.
     *
     * @param int $elementId
     * @param Site $site
     *
     * @return array|callable
     * @throws InvalidConfigException
     */
    public function source(int $elementId, Site $site): callable|array
    {
        $params = [
            'index' => $this->getIndexName($site),
            'id' => $elementId,
        ];

        return Elastic::$plugin->getElasticsearch()->getClient()->getSource($params);
    }

    /**
     * Returns all aliases and indexes of the configured Elasticsearch cluster.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function list(): array
    {
        $aliases = Elastic::$plugin->getElasticsearch()->getClient()->cat()->aliases();
        $indexes = Elastic::$plugin->getElasticsearch()->getClient()->cat()->indices();

        return [
            'aliases' => $aliases,
            'indexes' => $indexes,
        ];
    }

    /**
     * Get the analyzer result for a text.
     *
     * @param string $text The text to analyze.
     * @param Site $site The site to use.
     *
     * @return array
     * @throws InvalidConfigException
     */
    public function analyze(string $text, Site $site): array
    {
        $params = [
            'index' => $this->getIndexName($site),
            'body' => [
                'text' => $text,
                // Use the index default analyzer so the diagnostic reflects how content
                // fields are actually analyzed (standard + language stopwords).
                'analyzer' => 'default',
            ],
        ];

        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->analyze($params);
    }


    // Functions for analyzer
    // =========================================================================


    /**
     * Returns the stop word list for a language.
     *
     * @param Site $site The site to use.
     *
     * @return string
     */
    private function getStopWord(Site $site): string
    {
        // TODO: Allow own stop word list from settings

        $isoCountryCode = $site->getLocale()->getLanguageID();
        $esLanguage = $this->isoCountryCodeToElasticLanguage($isoCountryCode) ?? 'none';

        return '_' . $esLanguage . '_';
    }

    /**
     * Maps the ISO country code to the elasticsearch language name
     *
     * @param string $isoCode
     *
     * @return string|null
     */
    private function isoCountryCodeToElasticLanguage(string $isoCode): ?string
    {
        $mapping = [
            'ar' => 'arabic',
            'bg' => 'bulgarian',
            'bn' => 'bengali',
            'ca' => 'catalan',
            'cs' => 'czech',
            'da' => 'danish',
            'de' => 'german',
            'el' => 'greek',
            'en' => 'english',
            'es' => 'spanish',
            'eu' => 'basque',
            'fa' => 'persian',
            'fi' => 'finnish',
            'fr' => 'french',
            'ga' => 'irish',
            'gl' => 'galician',
            'hi' => 'hindi',
            'hu' => 'hungarian',
            'hy' => 'armenian',
            'id' => 'indonesian',
            'it' => 'italian',
            'ja' => 'cjk',
            'ko' => 'cjk',
            'lt' => 'lithuanian',
            'lv' => 'latvian',
            'nb' => 'norwegian',
            'nl' => 'dutch',
            'pt' => 'portuguese',
            'ro' => 'romanian',
            'ru' => 'russian',
            'sv' => 'swedish',
            'th' => 'thai',
            'tr' => 'turkish',
            'zh' => 'cjk',
        ];

        return $mapping[$isoCode] ?? null;
    }
}
