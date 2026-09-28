<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessIndexJob;
use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCP\BackgroundJob\IJobList;

class IndexJobScheduler
{
    public function __construct(
        private IndexJobRepository $jobs,
        private IJobList $jobList,
    ) {
    }

    public function enqueueIndex(
        string $fileId,
        ?string $ownerUid,
        ?string $etag,
        string $source = IndexJobRepository::SOURCE_INTERACTIVE,
    ): int
    {
        // Image jobs are only written to the connector queue. The image worker
        // polls that queue; calling IJobList::add() here would reset the
        // worker's last_checked on every file and starve it behind other jobs.
        return $this->jobs->enqueue($fileId, $ownerUid, $etag, IndexJobRepository::ACTION_INDEX, $source);
    }

    public function enqueueDelete(string $fileId, ?string $ownerUid = null): int
    {
        $jobId = $this->jobs->enqueue($fileId, $ownerUid, null, IndexJobRepository::ACTION_DELETE);
        $this->jobList->add(ProcessIndexJob::class, ['job_id' => $jobId]);
        return $jobId;
    }
}
