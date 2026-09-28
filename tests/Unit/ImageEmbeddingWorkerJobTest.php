<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\BackgroundJob\ImageEmbeddingWorkerJob;
use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingQueueWorker;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImageEmbeddingWorkerJobTest extends TestCase
{
    private ImageEmbeddingQueueWorker&MockObject $worker;
    private AppConfig&MockObject $config;
    private IJobList&MockObject $jobList;

    protected function setUp(): void
    {
        $this->worker = $this->createMock(ImageEmbeddingQueueWorker::class);
        $this->config = $this->createMock(AppConfig::class);
        $this->config->method('getImageWorkerSlots')->willReturn(3);
        $this->config->method('getWorkerTimeBudget')->willReturn(240);
        $this->jobList = $this->createMock(IJobList::class);
    }

    public function testPrimarySlotAddsOnlyMissingSlotsAndDrains(): void
    {
        $this->config->method('isIndexingEnabled')->willReturn(true);
        $this->jobList->method('has')->willReturnCallback(
            static fn (string $class, mixed $argument): bool => $argument === ['slot' => 1],
        );
        $this->jobList->expects(self::once())->method('add')->with(ImageEmbeddingWorkerJob::class, ['slot' => 2]);
        $this->worker->expects(self::once())->method('drain')->with(240)->willReturn(['batches' => 0, 'items' => 0]);

        $this->job()->runForTest(null);
    }

    public function testSurplusSlotRemovesItself(): void
    {
        $this->jobList->expects(self::once())->method('remove')->with(ImageEmbeddingWorkerJob::class, ['slot' => 5]);
        $this->worker->expects(self::never())->method('drain');

        $this->job()->runForTest(['slot' => 5]);
    }

    public function testDisabledIndexingDoesNotDrain(): void
    {
        $this->config->method('isIndexingEnabled')->willReturn(false);
        $this->jobList->method('has')->willReturn(true);
        $this->worker->expects(self::never())->method('drain');

        $this->job()->runForTest(['slot' => 1]);
    }

    private function job(): TestableImageEmbeddingWorkerJob
    {
        return new TestableImageEmbeddingWorkerJob(
            $this->createStub(ITimeFactory::class),
            $this->worker,
            $this->config,
            $this->jobList,
            $this->createMock(LoggerInterface::class),
        );
    }
}

class TestableImageEmbeddingWorkerJob extends ImageEmbeddingWorkerJob
{
    public function runForTest(mixed $argument): void
    {
        $this->run($argument);
    }
}
