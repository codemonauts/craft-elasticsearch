<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use Craft;
use Elasticsearch\Common\Exceptions\ElasticsearchException;
use RuntimeException;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\Exception;

/**
 * Base class for the plugin's console controllers.
 *
 * The search components (`indexes`, `elasticsearch`, ...) are only wired up once an endpoint is
 * configured (see Elastic::init()). Without one, every command would otherwise fail with an opaque
 * "Unknown component ID" error; this guard turns that into a clear message. Any Elasticsearch
 * client error raised while an action runs is likewise turned into a clear message instead of a
 * raw stack trace (see runAction()).
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

    /**
     * @inheritdoc
     * @throws Exception with a readable message when the Elasticsearch cluster errors out.
     */
    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (ElasticsearchException $e) {
            // Any Elasticsearch client error (unreachable cluster, transport/curl failure, 4xx/5xx
            // response) implements this interface. Surface a clear message + non-zero exit instead
            // of a raw stack trace, and log the detail for diagnosis.
            Craft::error('Elasticsearch request failed: ' . $e->getMessage(), 'elastic');

            throw new Exception(Craft::t('elastic', 'The Elasticsearch request failed: {message}. Check that the cluster is reachable and the endpoint is correct.', [
                'message' => $e->getMessage(),
            ]));
        } catch (InvalidConfigException $e) {
            // Misconfiguration surfaced while building the client (e.g. no valid authentication
            // method set). Turn it into a readable hint instead of a raw stack trace.
            Craft::error('Elasticsearch plugin misconfigured: ' . $e->getMessage(), 'elastic');

            throw new Exception(Craft::t('elastic', 'The Elasticsearch plugin is misconfigured: {message}. Check the connection settings.', [
                'message' => $e->getMessage(),
            ]));
        } catch (RuntimeException $e) {
            // Operational problems the services report with a ready-made message (unreadable or
            // malformed export files, ...). Pass the message through without a stack trace.
            Craft::error('Elasticsearch command failed: ' . $e->getMessage(), 'elastic');

            throw new Exception($e->getMessage());
        }
    }
}
