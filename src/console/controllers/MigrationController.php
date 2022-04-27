<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\jobs\ReindexUpdatedElements;
use Craft;
use craft\console\controllers\BackupTrait;
use craft\db\Table;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use Exception;
use yii\console\Controller;

class MigrationController extends Controller
{
    use BackupTrait;

    /**
     * Command to truncate Craft's full-text search database table.
     */
    public function actionTruncateTable()
    {
        if (!$this->confirm('Do you want to truncate Craft\'s full-text search database table?')) {
            return;
        }

        $this->backup();

        $this->stdout('Tuncating searchindex...' . PHP_EOL);

        try {
            Craft::$app->getDb()->createCommand()->truncateTable(Table::SEARCHINDEX)->execute();
        } catch (Exception $e) {
            $this->stdout('Error truncating table: ' . $e->getMessage() . PHP_EOL, Console::FG_RED);
        }

        $this->stdout('Table truncated.' . PHP_EOL, Console::FG_GREY);
    }

    /**
     * Command to reindex created and updated elements to database and Elasticsearch indexes starting at given date.
     *
     * @param string $date Date to start the re-indexing at.
     * @param bool $toDatabase Whether to re-index to the database index.
     * @param bool $toElasticsearch Whether to re-index to the Elasticsearch index.
     *
     * @throws Exception
     */
    public function actionReindex(string $date, bool $toDatabase = true, bool $toElasticsearch = true)
    {
        $startDate = DateTimeHelper::toDateTime($date, true);
        if (!$startDate) {
            $this->stderr("Unknown date: $date" . PHP_EOL, Console::FG_RED);
            return;
        }

        if (!$this->confirm('Re-index all elements created or updated since ' . $startDate->format(DATE_ISO8601) . '?')) {
            return;
        }

        Craft::$app->getQueue()->push(new ReindexUpdatedElements([
            'startDate' => $startDate,
            'toDatabaseIndex' => $toDatabase,
            'toElasticsearchIndex' => $toElasticsearch,
        ]));

        $this->stdout('Job queued.' . PHP_EOL, Console::FG_GREY);
    }
}