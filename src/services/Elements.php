<?php

namespace codemonauts\elastic\services;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\events\BeforeQueryEvent;
use codemonauts\elastic\models\Settings;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\models\Site;
use craft\search\SearchQuery;
use craft\search\SearchQueryTerm;
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
     * @var array Resolved scoring configuration (['default' => [...], 'fields' => [...]]) for the
     *            current search. Set at the start of search().
     */
    private array $scoring = [];

    /**
     * @var array<string, float> Resolved per-field boosts (handle => boost) for the current search.
     */
    private array $fieldBoosts = [];

    /**
     * @var bool Whether the cluster supports match_bool_prefix (else match_phrase_prefix).
     */
    private bool $boolPrefix = false;
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
     * @param bool $explain Whether to ask Elasticsearch to include the score explanation per hit.
     * @param int|null $size Maximum number of results to return. Defaults to 10000.
     *
     * @return array|callable
     * @throws InvalidConfigException
     */
    public function search(SearchQuery $searchQuery, array $scope, Site $site, bool $explain = false, ?int $size = null): callable|array
    {
        $indexes = Elastic::$plugin->getIndexes();

        $this->scoring = $this->scoringWeights();
        $this->fieldBoosts = $this->fieldBoostMap();
        $this->boolPrefix = Elastic::$plugin->getElasticsearch()->supportsMatchBoolPrefix();

        // Build one clause per term from Craft's flags. Top-level tokens are AND-ed (must); an
        // excluded term goes to must_not and contributes no score; an OR group becomes a nested
        // bool with minimum_should_match:1.
        $must = [];
        $mustNot = [];
        foreach ($searchQuery->getTokens() as $token) {
            if ($token instanceof SearchQueryTerm && $token->exclude) {
                $mustNot[] = $this->buildClause($token);
                continue;
            }

            $clause = $this->buildNode($token, $mustNot);
            if ($clause !== null) {
                $must[] = $clause;
            }
        }

        $bool = [];
        if (!empty($must)) {
            $bool['must'] = $must;
        }
        if (!empty($mustNot)) {
            $bool['must_not'] = $mustNot;
        }
        if (empty($bool)) {
            // Empty query: match everything (the scope filter below still applies).
            $bool['must'] = [['match_all' => new stdClass()]];
        }

        $params = [
            'index' => $indexes->getIndexName($site),
            'body' => [
                'size' => $size ?? 10000,
                'query' => [
                    'bool' => $bool,
                ],
            ],
        ];

        // Ask the cluster to return the score explanation for every hit.
        if ($explain) {
            $params['body']['explain'] = true;
        }

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
     * Builds the clause for a token — a term or an OR group. Excluded terms inside a group are
     * hoisted to $mustNot (absolute exclusion). Recurses so a group containing a group works.
     *
     * @param SearchQueryTerm|SearchQueryTermGroup $node
     * @param array $mustNot
     * @return array|null
     * @throws InvalidConfigException
     */
    private function buildNode(SearchQueryTerm|SearchQueryTermGroup $node, array &$mustNot): ?array
    {
        if ($node instanceof SearchQueryTermGroup) {
            $should = [];
            foreach ($node->terms as $member) {
                if ($member instanceof SearchQueryTerm && $member->exclude) {
                    $mustNot[] = $this->buildClause($member);
                    continue;
                }

                $clause = $this->buildNode($member, $mustNot);
                if ($clause !== null) {
                    $should[] = $clause;
                }
            }

            return empty($should) ? null : ['bool' => ['minimum_should_match' => 1, 'should' => $should]];
        }

        return $this->buildClause($node);
    }

    /**
     * Builds the query clause for a single term from its flags. `exact` and `phrase` are terminal;
     * everything else is a bool(minimum_should_match:1) where the token clause guarantees recall and
     * the narrower clauses only add score on top.
     *
     * @param SearchQueryTerm $term
     * @return array
     * @throws InvalidConfigException
     */
    private function buildClause(SearchQueryTerm $term): array
    {
        $termText = (string)$term->term;
        $onlyHandle = $term->attribute ?: null;

        // exact flag -> terminal exact match on the keyword subfield(s). Flag-mandated, so it is
        // emitted even when the exact weight is 0 (0 only omits the optional scoring tiers).
        if ($term->exact) {
            $clauses = $this->tierClauses('exact', $onlyHandle, fn(array $fields, $boost) => $this->exactClause($termText, $fields, $boost));
            if (empty($clauses)) {
                $clauses[] = $this->exactClause($termText, $this->tierFields('exact', $onlyHandle), 1);
            }

            return $this->anyOf($clauses);
        }

        // Preserve the pasted-slug behaviour: a hyphenated term is matched as a phrase.
        if ($term->phrase || str_contains($termText, '-')) {
            $clauses = $this->tierClauses('phrase', $onlyHandle, fn(array $fields, $boost) => [
                'multi_match' => ['query' => $termText, 'type' => 'phrase', 'fields' => $fields, 'boost' => $boost],
            ]);
            if (empty($clauses)) {
                $clauses[] = ['multi_match' => ['query' => $termText, 'type' => 'phrase', 'fields' => $this->tierFields('phrase', $onlyHandle)]];
            }

            return $this->anyOf($clauses);
        }

        // Recall via the token clause; exact/prefix/wildcard add score on top.
        $should = [];
        $should = array_merge($should, $this->tierClauses('exact', $onlyHandle, fn(array $fields, $boost) => $this->exactClause($termText, $fields, $boost)));
        $should = array_merge($should, $this->tierClauses('token', $onlyHandle, fn(array $fields, $boost) => [
            'multi_match' => ['query' => $termText, 'fields' => $fields, 'boost' => $boost],
        ]));

        if ($term->subLeft) {
            $should = array_merge($should, $this->tierClauses('wildcard', $onlyHandle, fn(array $fields, $boost) => [
                'query_string' => ['query' => '*' . $this->escape($termText) . '*', 'fields' => $fields, 'boost' => $boost],
            ]));
        } elseif ($term->subRight) {
            $type = $this->boolPrefix ? 'bool_prefix' : 'phrase_prefix';
            $should = array_merge($should, $this->tierClauses('prefix', $onlyHandle, fn(array $fields, $boost) => [
                'multi_match' => ['query' => $termText, 'fields' => $fields, 'type' => $type, 'boost' => $boost],
            ]));
        }

        if (empty($should)) {
            // Every scoring tier disabled: keep recall so the term still restricts results.
            $should[] = ['multi_match' => ['query' => $termText, 'fields' => $this->tierFields('token', $onlyHandle)]];
        }

        return ['bool' => ['minimum_should_match' => 1, 'should' => $should]];
    }

    /**
     * Wraps clauses so any one may match, or returns a single clause directly.
     */
    private function anyOf(array $clauses): array
    {
        return count($clauses) === 1 ? $clauses[0] : ['bool' => ['minimum_should_match' => 1, 'should' => $clauses]];
    }

    /**
     * The exact-match clause on the keyword subfield(s). The term is lowercased on the query side to
     * match the index-time lowercase normalizer; keyword fields do no analysis.
     */
    private function exactClause(string $term, array $fields, float|int $boost = 1): array
    {
        return ['multi_match' => ['query' => mb_strtolower($term), 'fields' => $fields, 'boost' => $boost]];
    }

    /**
     * Builds the clauses for one scoring tier: a base clause across all applicable fields at the
     * default tier weight, plus per-field emphasis clauses for any `scoring.fields` overrides. A
     * weight of 0 omits the clause; per-field weights multiply with the configured field boost.
     *
     * @param string $tier
     * @param string|null $onlyHandle Restrict to this single attribute/handle (attribute-scoped term).
     * @param callable $make fn(array $fields, float|int $boost): array
     * @return array
     * @throws InvalidConfigException
     */
    private function tierClauses(string $tier, ?string $onlyHandle, callable $make): array
    {
        $clauses = [];

        $default = $this->scoring['default'][$tier] ?? 0;
        if ($default > 0) {
            $fields = $this->tierFields($tier, $onlyHandle);
            if (!empty($fields)) {
                $clauses[] = $make($fields, $default);
            }
        }

        $indexes = Elastic::$plugin->getIndexes();
        foreach ($this->scoring['fields'] ?? [] as $handle => $weights) {
            if ($onlyHandle !== null && $handle !== $onlyHandle) {
                continue;
            }
            $weight = $weights[$tier] ?? null;
            if ($weight === null || $weight <= 0) {
                continue;
            }
            $boost = $weight * ($this->fieldBoosts[$handle] ?? 1);
            if ($boost <= 0) {
                continue;
            }
            $name = $indexes->mapAttributeToField($handle);
            $field = $tier === 'exact' ? [$name . '.exact'] : [$name];
            $clauses[] = $make($field, $boost);
        }

        return $clauses;
    }

    /**
     * The default field set for a tier: all fields via a wildcard plus any configured field boosts,
     * or the single resolved field when the term is attribute-scoped. The `exact` tier targets the
     * `.exact` keyword subfields — the suffix goes before the caret in `field^boost`.
     *
     * @throws InvalidConfigException
     */
    private function tierFields(string $tier, ?string $onlyHandle): array
    {
        $indexes = Elastic::$plugin->getIndexes();
        $exact = $tier === 'exact';

        if ($onlyHandle !== null) {
            $name = $indexes->mapAttributeToField($onlyHandle);

            return [$exact ? $name . '.exact' : $name];
        }

        $fields = [$exact ? '*.exact' : '*'];
        foreach ($this->fieldBoosts as $handle => $boost) {
            $name = $indexes->mapAttributeToField($handle);
            $fields[] = ($exact ? $name . '.exact' : $name) . '^' . $boost;
        }

        return $fields;
    }

    /**
     * Resolves the scoring configuration, filling in the default tier weights.
     */
    private function scoringWeights(): array
    {
        $configured = is_array(Elastic::$settings->scoring) ? Elastic::$settings->scoring : [];

        return [
            // Cast to float so string values coming from the settings form are usable as boosts.
            'default' => array_map('floatval', Elastic::$settings->resolvedScoringDefaults()),
            'fields' => $configured['fields'] ?? [],
        ];
    }

    /**
     * The configured per-field boosts as a handle => boost map.
     */
    private function fieldBoostMap(): array
    {
        $boosts = [];
        if (is_array(Elastic::$settings->fieldBoosts)) {
            foreach (Elastic::$settings->fieldBoosts as $entry) {
                if (isset($entry['handle'], $entry['boost'])) {
                    $boosts[$entry['handle']] = (float)$entry['boost'];
                }
            }
        }

        return $boosts;
    }

    /**
     * Escapes Lucene query-string special characters in a term. Only the query_string wildcard clause
     * needs this; multi_match/match take the term verbatim.
     */
    private function escape(string $term): string
    {
        return preg_replace('/[+\-=&|!(){}\[\]^"~*?:\\\\\/<>]/', '\\\\$0', $term);
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

        foreach (($result['hits']['hits'] ?? []) as $row) {
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

        $result = Elastic::$plugin->getElasticsearch()->getClient()->bulk($params);

        if ($result['errors'] ?? false) {
            // Partial failures don't throw; surface them so a silently-incomplete delete is visible.
            Craft::error('Some Elasticsearch bulk deletes failed for site ' . $site->id . '.', 'elastic');
        }

        return $result;
    }
}
