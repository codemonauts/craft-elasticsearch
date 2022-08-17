<?php

namespace codemonauts\elastic\events;

use yii\base\Event;

class BeforeQueryEvent extends Event
{
    /**
     * @var array The params that will be sent to Elasticsearch.
     */
    public array $params = [];
}
