<?php

namespace codemonauts\elastic\events;

use yii\base\Event;

/**
 * Carries the query parameters before they are sent to Elasticsearch.
 */
class BeforeQueryEvent extends Event
{
    /**
     * @var array The params that will be sent to Elasticsearch.
     */
    public array $params = [];
}
