<?php

namespace codemonauts\elastic\services;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\events\BeforeQueryEvent;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\models\Site;
use craft\search\SearchQuery;
use craft\search\SearchQueryTermGroup;
use Exception;
use stdClass;
use yii\base\InvalidConfigException;

/**
 * Manage elements in index
 */
class Elements extends Component
{
    /**
     * @event BeforeQueryEvent The event that is triggered before the query is sent to ELasticsearch.
     */
    public const EVENT_BEFORE_QUERY = 'beforeQuery';

    /**
     * Adds the keywords of an element to the Elasticsearch index of a site.
     *
     * @param ElementInterface $element The element to store the keywords for.
     * @param Site $site The site to use.
     * @param array $keywords The preprocessed, whitespace separated keywords to use.
     *
     * @return array|callable
     * @throws InvalidConfigException
     */
    public function add(ElementInterface $element, Site $site, array $keywords): callable|array
    {
        $fieldPrefix = Elastic::$settings->fieldPrefix;
        $indexes = Elastic::$plugin->getIndexes();
        $body = [];

        foreach ($keywords as $handle => $value) {
            $body[$fieldPrefix . $handle] = $value;
        }

        $params = [
            'id' => $element->id,
            'index' => $indexes->getIndexName($site),
            'body' => $body,
        ];

        $indexes->ensureIndexForSiteExists($site);

        return Elastic::$plugin->getElasticsearch()->getClient()->index($params);
    }

    /**
     * Delete an element from an index.
     *
     * @param int $elementId The element to delete from all indexes.
     *
     * @throws Exception
     */
    public function delete(int $elementId, Site $site)
    {
        $indexName = Elastic::$plugin->getIndexes()->getIndexName($site);

        $params = [
            'index' => $indexName,
            'id' => $elementId,
        ];

        try {
            Elastic::$plugin->getElasticsearch()->getClient()->delete($params);
        } catch (Exception $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Search for the given query in Elasticsearch.
     *
     * @param SearchQuery $searchQuery The search query to use.
     * @param array $scope The list of IDs to include in results.
     * @param Site $site The site to search in.
     *
     * @return array|callable
     * @throws InvalidConfigException
     */
    public function search(SearchQuery $searchQuery, array $scope, Site $site): callable|array
    {
        $indexes = Elastic::$plugin->getIndexes();
        $settings = Elastic::$settings;

        // Get tokens for query
        $tokens = $searchQuery->getTokens();

        // Set Terms and Groups based on tokens
        $queryTokens = [];
        foreach ($tokens as $token) {
            if ($token instanceof SearchQueryTermGroup) {
                // TODO: Add grouping.
            } else {
                // Special case for pasted slugs
                if (str_contains($token->term, '-')) {
                    $token->phrase = true;
                }
                $queryString = '';
                if ($token->subLeft && !$token->phrase) {
                    $queryString .= '*';
                }
                if ($token->phrase) {
                    if ($token->attribute) {
                        $queryString .= $indexes->mapAttributeToField($token->attribute) . ':';
                    }
                    $queryString .= '"' . trim($token->term) . '"';
                } else {
                    if ($token->exclude) {
                        $queryString .= '-';
                    } else {
                        $queryString .= '+';
                    }
                    if ($token->attribute) {
                        $queryString .= $indexes->mapAttributeToField($token->attribute) . ':';
                    }
                    $queryString .= trim($token->term);
                }
                if ($token->subRight && !$token->phrase) {
                    $queryString .= '*';
                }
                $queryTokens[] = $queryString;
            }
        }

        // Add boosts
        $fields = ['*'];
        if (is_array($settings->fieldBoosts)) {
            foreach ($settings->fieldBoosts as $field) {
                $fields[] = $indexes->mapAttributeToField($field['handle']) . '^' . $field['boost'];
            }
        }

        $params = [
            'index' => $indexes->getIndexName($site),
            'body' => [
                'size' => 10000,
                'query' => [
                    'bool' => [
                        'must' => [
                            'query_string' => [
                                'fields' => $fields,
                                'query' => implode(' ', $queryTokens),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Add optional scope of IDs
        if (count($scope)) {
            $params['body']['query']['bool']['filter'] = [
                'ids' => [
                    'values' => $scope,
                ],
            ];
        }

        // Allow plugins to modify the query parameters
        $event = new BeforeQueryEvent([
            'params' => $params,
        ]);
        $this->trigger(self::EVENT_BEFORE_QUERY, $event);
        $params = $event->params;

        return Elastic::$plugin->getElasticsearch()->getClient()->search($params);
    }

    /**
     * Returns all indexed IDs from Elasticsearch.
     *
     * @param Site $site The site to get all indexed IDs from.
     *
     * @return int[]
     * @throws InvalidConfigException
     */
    public function getAllIndexedIds(Site $site): array
    {
        $indexName = Elastic::$plugin->getIndexes()->getIndexName($site);
        $returnValue = [];

        $params = [
            'index' => $indexName,
            'body' => [
                'query' => [
                    'match_all' => new stdClass(),
                ],
                'stored_fields' => [],
            ],
        ];

        $result = Elastic::$plugin->getElasticsearch()->getClient()->search($params);

        foreach ($result['hits']['hits'] as $row) {
            $returnValue[] = (int)$row['_id'];
        }

        return $returnValue;
    }

    /**
     * Buld delete elements from index.
     *
     * @param int[] $ids The IDs to delete from site's index.
     * @param Site $site The site to delete the IDs from.
     *
     * @return array|callable|false
     * @throws InvalidConfigException
     */
    public function bulkDelete(array $ids, Site $site): callable|bool|array
    {
        if (empty($ids)) {
            return false;
        }

        $indexName = Elastic::$plugin->getIndexes()->getIndexName($site);
        $params = [
            'body' => [],
        ];

        foreach ($ids as $id) {
            $params['body'][] = [
                'delete' => [
                    '_index' => $indexName,
                    '_id' => $id,
                ],
            ];
        }

        return Elastic::$plugin->getElasticsearch()->getClient()->bulk($params);
    }
}