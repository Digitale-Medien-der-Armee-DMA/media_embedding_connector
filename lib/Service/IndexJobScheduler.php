<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\BackgroundJob\ProcessImageEmbeddingBatchJob;
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
        $jobId = $this->jobs->enqueue($fileId, $ownerUid, $etag, IndexJobRepository::ACTION_INDEX, $source);
        $this->jobList->add(ProcessImageEmbeddingBatchJob::class, ['batch_size' => null]);
        return $jobId;
    }

    public function enqueueDelete(string $fileId, ?string $ownerUid = null): int
    {
        $jobId = $this->jobs->enqueue($fileId, $ownerUid, null, IndexJobRepository::ACTION_DELETE);
        $this->jobList->add(ProcessIndexJob::class, ['job_id' => $jobId]);
        return $jobId;
    }
}
