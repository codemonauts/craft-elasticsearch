<?php

namespace codemonauts\elastic;

use codemonauts\elastic\jobs\ReindexUpdatedElements;
use codemonauts\elastic\jobs\UpdateElasticsearchIndex;
use codemonauts\elastic\jobs\UpdateMapping;
use codemonauts\elastic\models\Settings;
use codemonauts\elastic\services\Elasticsearch;
use codemonauts\elastic\services\Indexes;
use codemonauts\elastic\services\Search;
use codemonauts\elastic\utilities\IndexUtility;
use Craft;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Utilities;
use craft\web\Controller;

/**
 * @property Elasticsearch $elasticsearch
 * @property Indexes $indexes
 * @property Search $search
 */
class Elastic extends Plugin
{
    /**
     * @var int The mapping schema version stamped into every index this plugin creates.
     *
     * Increment whenever buildMapping() or buildSettings() change in a way that requires a
     * reindex; drift detection compares this against each index's stamp.
     *
     * v2: the analyzer moved to the index-level "default" slot and was removed from field
     *     mappings. Existing indexes keep working, but a rebuild is required to pick up the
     *     language-aware stopword handling.
     * v3: every text field gained an ".exact" keyword subfield (with a lowercasing
     *     normalizer) for exact, whole-value matching and scoring. Existing indexes keep
     *     working, but the exact tier only populates after a rebuild.
     */
    public const MAPPING_VERSION = 3;

    /**
     * @var Elastic|null
     */
    public static ?Elastic $plugin;

    /**
     * @var Settings|null
     */
    public static ?Settings $settings;

    /**
     * @inheritDoc
     */
    public bool $hasCpSettings = true;

    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        self::$settings = self::$plugin->getSettings();

        // If no endpoint is set, do nothing.
        if (App::parseEnv(self::$settings->endpoint) === '') {
            return;
        }

        // If not in transition mode, replace the Craft internal search component
        if (!self::$settings->transition) {
            $componentConfig = [
                'search' => Search::class,
            ];
            Craft::$app->setComponents($componentConfig);
        }

        // Add the plugin search components
        $componentConfig = [
            'elasticsearch' => [
                'class' => Elasticsearch::class,
                'hosts' => [
                    App::parseEnv(self::$settings->endpoint),
                ],
                'authentication' => App::parseEnv(self::$settings->authentication),
                'username' => App::parseEnv(self::$settings->username),
                'password' => App::parseEnv(self::$settings->password),
                'region' => App::parseEnv(self::$settings->region),
            ],
            'indexes' => [
                'class' => Indexes::class,
                'indexName' => App::parseEnv(self::$settings->indexName),
            ],
            'elements' => services\Elements::class,
            'search' => Search::class,
        ];
        $this->setComponents($componentConfig);

        // When in transition mode, add event to update Elasticsearch indexes as well.
        if (self::$settings->transition) {
            Craft::$app->elements->on(Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
                $element = $event->element;
                $elementType = get_class($element);

                if (ElementHelper::isDraftOrRevision($element)) {
                    return;
                }

                Craft::$app->getQueue()->push(new UpdateElasticsearchIndex([
                    'elementType' => $elementType,
                    'elementId' => $element->id,
                ]));
            });
        }

        // Register event when changing field definitions
        Craft::$app->fields->on(Fields::EVENT_AFTER_SAVE_FIELD, function() {
            Craft::$app->queue->push(new UpdateMapping());
        });

        // Register utilities
        Craft::$app->getUtilities()->on(Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = IndexUtility::class;
        });

        // Register settings event
        $this->on(Plugin::EVENT_BEFORE_SAVE_SETTINGS, function(ModelEvent $event) {
            $settings = $event->sender->getSettings();

            // Mode has changed
            if ($settings->lastMode !== $settings->transition) {
                // Switch from transition mode to full mode, save the timestamp
                if ($settings->lastMode === true) {
                    $settings->lastMode = false;
                    $settings->lastSwitch = time();
                } else {
                    Craft::$app->getQueue()->push(new ReindexUpdatedElements([
                        'startDate' => DateTimeHelper::toDateTime($settings->lastSwitch),
                        'toDatabaseIndex' => true,
                    ]));
                    $settings->lastMode = true;
                    $settings->lastSwitch = 0;
                }
            }
        });
    }

    /**
     * @inheritDoc
     */
    protected function afterInstall(): void
    {
        parent::afterInstall();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        Craft::$app->getResponse()->redirect(
            UrlHelper::cpUrl('settings/plugins/elastic')
        )->send();
    }

    /**
     * @inheritDoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritDoc
     */
    public function getSettingsResponse(): mixed
    {
        /** @var Settings $settings */
        $settings = $this->getSettings();

        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('elastic/settings', [
                'plugin' => $this,
                'settings' => $settings,
                'authenticationSuggestions' => [
                    [
                        'label' => Craft::t('elastic', 'Methods'),
                        'data' => [
                            ['name' => 'none', 'hint' => Craft::t('elastic', 'No authentication')],
                            ['name' => 'basicauth', 'hint' => Craft::t('elastic', 'Username & password')],
                            ['name' => 'aws', 'hint' => Craft::t('elastic', 'AWS IAM credentials')],
                        ],
                    ],
                ],
                'boostsCols' => [
                    'handle' => [
                        'heading' => 'Field handle*',
                        'type' => 'singleline',
                    ],
                    'boost' => [
                        'heading' => 'Boost*',
                        'type' => 'number',
                    ],
                ],
                // Resolved scoring tier weights (configured values merged over the defaults).
                'scoringWeights' => array_merge(Settings::SCORING_DEFAULTS, $settings->scoring['default'] ?? []),
                'scoringHints' => [
                    'exact' => Craft::t('elastic', 'Whole-value match on a field (e.g. an artist named exactly “Abba”).'),
                    'phrase' => Craft::t('elastic', 'The term matched as a phrase.'),
                    'token' => Craft::t('elastic', 'A whole-word match — guarantees recall; the other tiers only reorder.'),
                    'prefix' => Craft::t('elastic', 'A word-prefix match (e.g. “abb” matches “abba”).'),
                    'wildcard' => Craft::t('elastic', 'A sub-word / wildcard match, only when the term asks for it.'),
                ],
            ]
        );
    }

    public function getElasticsearch(): Elasticsearch
    {
        return $this->get('elasticsearch');
    }

    public function getIndexes(): Indexes
    {
        return $this->get('indexes');
    }

    public function getSearch(): Search
    {
        return $this->get('search');
    }

    public function getElements(): services\Elements
    {
        return $this->get('elements');
    }
}
