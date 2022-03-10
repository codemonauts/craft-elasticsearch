<?php

namespace codemonauts\elastic;

use codemonauts\elastic\jobs\UpdateMapping;
use codemonauts\elastic\jobs\UpdateSearchIndex;
use codemonauts\elastic\models\Settings;
use codemonauts\elastic\services\Elasticsearch;
use codemonauts\elastic\services\Indexes;
use codemonauts\elastic\services\Search;
use codemonauts\elastic\utilities\IndexUtility;
use Craft;
use craft\base\ElementInterface;
use craft\base\Plugin;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Utilities;

/**
 * @property Elasticsearch $elasticsearch
 * @property Indexes $indexes
 * @property Search $search
 */
class Elastic extends Plugin
{
    /**
     * @var Elastic
     */
    public static $plugin;

    /**
     * @var Settings
     */
    public static $settings;

    /**
     * @inheritDoc
     */
    public $hasCpSettings = true;

    /**
     * @inheritDoc
     */
    public function init(): void
    {
        parent::init();

        self::$plugin = $this;

        self::$settings = self::$plugin->getSettings();

        // If no endpoint is set, do nothing.
        if (Elastic::env(self::$settings->endpoint) === '') {
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
                    Elastic::env(self::$settings->endpoint),
                ],
                'authentication' => self::$settings->authentication,
                'username' => Elastic::env(self::$settings->username),
                'password' => Elastic::env(self::$settings->password),
                'region' => Elastic::env(self::$settings->region),
            ],
            'indexes' => [
                'class' => Indexes::class,
                'indexName' => Elastic::env(self::$settings->indexName),
            ],
            'elements' => services\Elements::class,
            'search' => Search::class,
        ];
        $this->setComponents($componentConfig);

        // When in transition mode, add event to update Elasticsearch indexes as well.
        if (self::$settings->transition) {
            Craft::$app->elements->on(Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
                /**
                 * @var ElementInterface $element
                 */
                $element = $event->element;
                $elementType = get_class($element);

                if (ElementHelper::isDraftOrRevision($element)) {
                    return;
                }

                if (count($element::searchableAttributes()) === 0) {
                    return;
                }

                Craft::$app->getQueue()->push(new UpdateSearchIndex([
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
        Craft::$app->getUtilities()->on(Utilities::EVENT_REGISTER_UTILITY_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = IndexUtility::class;
        });
    }

    /**
     * @inheritDoc
     */
    public function afterInstall()
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
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * @inheritDoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('elastic/settings', [
                'settings' => $this->getSettings(),
                'authenticationOptions' => [
                    'aws' => 'AWS',
                    'basicauth' => 'BasicAuth'
                ]
            ]
        );
    }

    /**
     * Parse environment string. Should be replaced with Craft's App::parseEnv after dropping 3.6 support.
     *
     * @param string|null $str The string to parse.
     *
     * @return array|string|null
     */
    public static function env(string $str = null)
    {
        if ($str === null) {
            return null;
        }

        if (preg_match('/^\$(\w+)$/', $str, $matches)) {
            $value = App::env($matches[1]);
            if ($value !== false) {
                $str = $value;
            }
        }

        return $str;
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
