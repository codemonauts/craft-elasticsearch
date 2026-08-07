<?php

namespace codemonauts\elastic\console\controllers;

use codemonauts\elastic\Elastic;
use codemonauts\elastic\jobs\ReindexUpdatedElements;
use codemonauts\elastic\services\Indexes;
use Craft;
use craft\console\controllers\BackupTrait;
use craft\db\Table;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use Exception;
use yii\console\ExitCode;

class MigrationController extends BaseController
{
    use BackupTrait;

    /**
     * @var string[] Actions that work without a configured endpoint.
     */
    protected array $unguardedActions = ['index'];

    /**
     * Lists the available migration commands.
     */
    public function actionIndex(): int
    {
        $commands = [
            'elastic/migration/truncate-table' => 'Truncate Craft\'s full-text search database table.',
            'elastic/migration/reindex <date>' => 'Reindex elements created or updated since <date> to the database and Elasticsearch indexes.',
        ];

        $this->stdout(PHP_EOL . 'Available migration commands:' . PHP_EOL . PHP_EOL, Console::FG_YELLOW);

        foreach ($commands as $command => $description) {
            $this->stdout('  ' . $command . PHP_EOL, Console::FG_GREEN);
            $this->stdout('    ' . $description . PHP_EOL . PHP_EOL);
        }

        $this->stdout('Run "php craft help <command>" for the full options of a command.' . PHP_EOL);

        return ExitCode::OK;
    }

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

        // Warn before reindexing documents into an index whose schema is behind the plugin.
        if ($toElasticsearch) {
            foreach (Elastic::$plugin->getIndexes()->detectMappingDrift() as $drift) {
                if ($drift['status'] === Indexes::STATUS_CURRENT) {
                    continue;
                }
                $this->stdout('Schema drift on index "' . $drift['alias'] . '" (' . $drift['status']
                    . '). Reindexing documents will not fix the schema — run '
                    . '"php craft elastic/index/reindex" to recreate the index with the current schema.' . PHP_EOL,
                    Console::FG_YELLOW);
            }
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
