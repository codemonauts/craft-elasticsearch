<?php

namespace codemonauts\elastic\services;

use codemonauts\elastic\Elastic;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\models\Site;
use Exception;
use yii\base\InvalidConfigException;

/**
 * Index handling
 */
class Indexes extends Component
{
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
            foreach ($indexDetails['aliases'] as $aliasName => $aliasDetails) {
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

        if (count($result['failures']) > 0) {
            return false;
        }

        $returnValue['total'] = $result['total'];
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

        return array_keys($result)[0];
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
     * Returns the mapping configuration for a site.
     *
     * @return array
     */
    public function buildMapping(): array
    {
        $fieldPrefix = Elastic::$settings->fieldPrefix;
        $mapping = [];

        $predefinedAttributes = [
            'title' => [
                'type' => 'text',
                'analyzer' => 'standard',
            ],
            'slug' => [
                'type' => 'text',
                'analyzer' => 'standard',
            ],
            'postDate' => [
                'type' => 'date',
            ],
        ];

        foreach ($predefinedAttributes as $attribute => $config) {
            $mapping[$fieldPrefix . 'attribute_' . $attribute] = $config;
        }

        /**
         * @var ElementInterface $elementType
         */
        $elementTypes = Craft::$app->elements->getAllElementTypes();
        foreach ($elementTypes as $elementType) {
            foreach ($elementType::searchableAttributes() as $attribute) {
                $mapping[$fieldPrefix . 'attribute_' . $attribute] = [
                    'type' => 'text',
                    'analyzer' => 'standard',
                ];
            }
        }

        $fields = Craft::$app->getFields()->getAllFields();
        foreach ($fields as $field) {
            if ($field->searchable) {
                $mapping[$fieldPrefix . 'field_' . $field->id] = [
                    'type' => 'text',
                    'analyzer' => 'standard',
                ];
            }
        }

        return $mapping;
    }

    public function buildSettings(Site $site): array
    {
        $language = $this->getStopWord($site);

        return [
            "analysis" => [
                "analyzer" => [
                    "standard_stopwords" => [
                        "type" => "standard",
                        "stopwords" => $language,
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

        return Elastic::$plugin->getElasticsearch()->getClient()->indices()->putMapping($params);
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
            } else if (str_starts_with($fieldName, $fieldNeedle)) {
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
                'analyzer' => 'standard',
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
     * Returns the analyzer language for a site's language.
     *
     * @param Site $site The site to use.
     *
     * @return string
     */
    private function getAnalyzerLanguage(Site $site): string
    {
        $isoCountryCode = $site->getLocale()->getLanguageID();

        return $this->isoCountryCodeToElasticLanguage($isoCountryCode) ?? 'standard';
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