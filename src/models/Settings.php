<?php

namespace codemonauts\elastic\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
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
    public $endpoint = '';

    /**
     * @var string|null The authentication method to use. Valid values are 'aws' for IAM credentials or instance
     *                  profiles and 'basicauth' for all other realms with username and password authentication.
     */
    public $authentication = null;

    /**
     * @var string|null The username or IAM access key.
     */
    public $username = null;

    /**
     * @var string|null The password or IAM secret key.
     */
    public $password = null;

    /**
     * @var string|null The AWS region the AWS OpenSearch domain is in.
     */
    public $region = null;

    /**
     * @var string The index name to use. It will be prepended to every site's handle.
     */
    public $indexName = 'craftcms';

    /**
     * @var string Prefix for all field handles. It prevents collisions with reserved names.
     */
    public $fieldPrefix = 'craft_';

    /**
     * @var array Boosts for fields.
     */
    public $fieldBoosts = null;

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['endpoint', 'indexName', 'fieldPrefix'], 'required'],
            ['region', 'required', 'when' => function ($model) {
                return $model->authentication === 'aws';
            }, 'message' => Craft::t('elastic', 'Region cannot be blank when using AWS.')],
        ];
    }

}
