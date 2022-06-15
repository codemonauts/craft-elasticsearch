<?php

namespace codemonauts\elastic\jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;

class UpdateDatabaseIndex extends BaseJob
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
        $search = Craft::$app->getSearch();

        $elements = $class::find()
            ->drafts(null)
            ->id($this->elementId)
            ->siteId($this->siteId)
            ->provisionalDrafts(null)
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
        return Craft::t('elastic', 'Updating database search index');
    }
}
