<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use Craft;
use yii\console\Controller;
use yii\console\Exception;

/**
 * Base class for the plugin's console controllers.
 *
 * The search components (`indexes`, `elasticsearch`, ...) are only wired up once an endpoint is
 * configured (see Elastic::init()). Without one, every command would otherwise fail with an opaque
 * "Unknown component ID" error; this guard turns that into a clear message.
 */
abstract class BaseController extends Controller
{
    /**
     * @var string[] Action IDs that work without a configured endpoint (e.g. help listings).
     */
    protected array $unguardedActions = [];

    /**
     * @inheritdoc
     * @throws Exception if no Elasticsearch endpoint is configured.
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!in_array($action->id, $this->unguardedActions, true) && !Elastic::$plugin->isConfigured()) {
            throw new Exception(Craft::t('elastic', 'No Elasticsearch endpoint is configured. Set the endpoint on the plugin settings page before running elastic commands.'));
        }

        return true;
    }
}
