<?php

namespace codemonauts\elastic\jobs;

use codemonauts\elastic\Elastic;
use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

class UpdateElasticsearchIndex extends BaseJob
{
    /**
     * @var string|ElementInterface|null The type of elements to update.
     */
    public string|ElementInterface|null $elementType;

    /**
     * @var int|int[]|null The ID(s) of the element(s) to update
     */
    public int|array|null $elementId;

    /**
     * @var int|string|null The site ID of the elements to update, or `'*'` to update all sites
     */
    public int|string|null $siteId = '*';

    /**
     * @inheritDoc
     */
    public function execute($queue): void
    {
        $class = $this->elementType;
        $search = Elastic::$plugin->getSearch();

        // Drafts and revisions are deliberately left out: the after-save handler skips them via
        // ElementHelper::isDraftOrRevision(), so indexing them here would put content into the
        // index that the regular indexing path never writes. Revisions and trashed elements are
        // already excluded by the query defaults.
        $elements = $class::find()
            ->id($this->elementId)
            ->siteId($this->siteId)
            ->status(null)
            ->all();

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
