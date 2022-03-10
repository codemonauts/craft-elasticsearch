<?php

namespace codemonauts\elastic\jobs;

use codemonauts\elastic\Elastic;
use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

class UpdateSearchIndex extends BaseJob
{
    /**
     * @var string|ElementInterface|null The type of elements to update.
     */
    public $elementType;

    /**
     * @var int|int[]|null The ID(s) of the element(s) to update
     */
    public $elementId;

    /**
     * @var int|string|null The site ID of the elements to update, or `'*'` to update all sites
     */
    public $siteId = '*';

    /**
     * @inheritDoc
     */
    public function execute($queue)
    {
        $class = $this->elementType;
        $search = Elastic::$plugin->getSearch();

        $query = $class::find()
            ->drafts(null)
            ->id($this->elementId)
            ->siteId($this->siteId)
            ->anyStatus();

        // TODO: Remove when dropping 3.6 support.
        $craft37 = version_compare(Craft::$app->getVersion(), '3.7', '>=');
        if ($craft37) {
            $query->provisionalDrafts(null);
        }

        $elements = $query->all();
        $total = count($elements);

        foreach ($elements as $i => $element) {
            $this->setProgress($queue, ($i + 1) / $total);
            $search->indexElementAttributes($element);
        }
    }

    /**
     * @inheritDoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('elastic', 'Updating Elasticsearch indexes');
    }
}
