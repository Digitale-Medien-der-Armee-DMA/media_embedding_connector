<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Migration;

use OCA\MediaEmbeddingConnector\AppInfo\Application;
use OCA\MediaEmbeddingConnector\BackgroundJob\DiscoverBackfillUsersJob;
use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessImageEmbeddingBatchJob;
use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessIndexJob;
use OCA\MediaEmbeddingConnector\BackgroundJob\ScanBackfillFolderJob;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Removes everything the connector owns inside Nextcloud when the app is
 * uninstalled: queued background jobs, the app configuration including the
 * stored Media Embedding Service and Elasticsearch credentials, and the connector
 * tables.
 *
 * The Elasticsearch indices live outside Nextcloud and are intentionally left
 * untouched. Deleting them stays an explicit administrator action so that an
 * uninstall can never destroy an index that is still in use.
 */
class UninstallCleanup implements IRepairStep
{
    /**
     * Tables created by the connector migrations, dropped in reverse
     * dependency order.
     *
     * @var list<string>
     */
    private const TABLES = [
        'media_embed_audit',
        'media_embed_state',
        'media_embed_skips',
        'media_embed_idx_jobs',
        'media_embed_idx_files',
    ];

    /**
     * @var list<class-string>
     */
    private const JOBS = [
        ProcessIndexJob::class,
        ProcessImageEmbeddingBatchJob::class,
        DiscoverBackfillUsersJob::class,
        ScanBackfillFolderJob::class,
    ];

    public function __construct(
        private IDBConnection $db,
        private IAppConfig $appConfig,
        private IJobList $jobList,
    ) {
    }

    public function getName(): string
    {
        return 'Remove Media Embedding Connector jobs, configuration, and tables';
    }

    public function run(IOutput $output): void
    {
        foreach (self::JOBS as $job) {
            $this->jobList->remove($job);
        }
        $output->info('Removed queued connector background jobs.');

        $this->appConfig->deleteApp(Application::APP_ID);
        $output->info('Removed connector configuration including stored credentials.');

        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->dropTable($table);
                $output->info('Dropped table ' . $table . '.');
            }
        }

        $output->info(
            'Elasticsearch indices and aliases created by the connector were kept. '
            . 'Delete them manually if they are no longer needed.',
        );
    }
}
