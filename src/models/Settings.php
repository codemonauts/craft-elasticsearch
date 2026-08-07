<?php

namespace codemonauts\elastic\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    /**
     * @var int[] Default scoring weight per match tier. Single source of truth for both the query
     *            builder (Elements::scoringWeights()) and the settings page.
     */
    public const SCORING_DEFAULTS = [
        'exact' => 50,
        'phrase' => 10,
        'token' => 5,
        'prefix' => 3,
        'wildcard' => 1,
    ];

    /**
     * @var bool Running in transition mode. Both, the Craft internal search index and the Elasticsearch index are
     *           filled but only the Craft internal search index will be used for searching.
     */
    public bool $transition = true;

    /**
     * @var bool Status of the transition mode before saving the settings.
     */
    public bool $lastMode = true;

    /**
     * @var int Timestamp of the last deactivating of the transition mode. Used to smooth switch back and reindex
     *          elements updated in the meantime.
     */
    public int $lastSwitch = 0;

    /**
     * @var string The endpoint URL to use.
     */
    public string $endpoint = '';

    /**
     * @var string|null The authentication method to use. Valid values are 'none' for an endpoint without
     *                  authentication, 'basicauth' for username and password authentication and 'aws' for IAM
     *                  credentials or instance profiles.
     */
    public ?string $authentication = null;

    /**
     * @var string|null The username or IAM access key.
     */
    public ?string $username = null;

    /**
     * @var string|null The password or IAM secret key.
     */
    public ?string $password = null;

    /**
     * @var string|null The AWS region the AWS OpenSearch domain is in.
     */
    public ?string $region = null;

    /**
     * @var string The index name to use. It will be prepended to every site's handle.
     */
    public string $indexName = 'craftcms';

    /**
     * @var string Prefix for all field handles. It prevents collisions with reserved names.
     */
    public string $fieldPrefix = 'craft_';

    /**
     * @var array|null Boosts for fields.
     */
    public ?array $fieldBoosts = null;

    /**
     * @var array|null Scoring weights per clause tier. `null` uses the defaults in
     *                 Elements::scoringWeights(). Weights only affect ordering — which clauses
     *                 exist at all is decided by Craft's term flags, not by this config. A weight
     *                 of 0 omits that clause. Per-field weights under `fields` override the
     *                 default for that field and multiply with any configured `fieldBoosts`.
     *
     *                 [ 'default' => ['exact'=>50,'phrase'=>10,'token'=>5,'prefix'=>3,'wildcard'=>1],
     *                   'fields'  => ['artist' => ['exact' => 100]] ]
     */
    public ?array $scoring = null;

    /**
     * @var bool|null Force the match_bool_prefix behaviour. `null` auto-detects cluster support
     *                (match_bool_prefix requires Elasticsearch 7.2+; all OpenSearch versions
     *                support it). `true`/`false` force it on/off; when off, prefix scoring falls
     *                back to match_phrase_prefix.
     */
    public ?bool $matchBoolPrefix = null;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['endpoint', 'indexName', 'fieldPrefix'], 'required'],
            ['region', 'required', 'when' => function($model) {
                return $model->authentication === 'aws';
            }, 'message' => Craft::t('elastic', 'Region cannot be blank when using AWS.')],
        ];
    }
}
