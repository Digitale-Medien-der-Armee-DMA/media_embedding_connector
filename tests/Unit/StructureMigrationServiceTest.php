<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\{IndexedFileRepository, StateRepository, StructureMetadataRepository, StructureTaskRepository};
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCA\MediaEmbeddingConnector\Service\{ElasticsearchClient, IndexLifecycleService, StructureMigrationService};
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class StructureMigrationServiceTest extends TestCase
{
    private const INDEX = 'nc_media_embeddings_clip_2_fp_v1';
    private array $status = [];
    private array $errors = [];
    private array $values = [];

    public function testExistingVectorsAreUpgradedInPlaceAndRestartIsIdempotent(): void
    {
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $metadata->method('metadata')->willReturn(['structure_schema' => 2, 'storage_numeric_id' => 7, 'ancestor_ids' => [1, 10]]);
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::never())->method('upsertDocument');
        $es->expects(self::exactly(2))->method('updateStructureBatch')->with(self::INDEX, [
            41 => ['structure_schema' => 2, 'storage_numeric_id' => 7, 'ancestor_ids' => [1, 10]],
            42 => ['structure_schema' => 2, 'storage_numeric_id' => 7, 'ancestor_ids' => [1, 10]],
        ])->willReturn([]);
        $migration = $this->service($metadata, $es, ['41', '42']);
        $migration->runSlice(1);
        self::assertSame('completed', $this->status['status']);
        self::assertSame(2, $this->status['processed']);
        $migration->requireReady(self::INDEX);
        $migration->control('restart');
        self::assertTrue($migration->getStatus()['restart_pending']);
        $migration->runSlice(1);
        self::assertSame('completed', $this->status['status']);
    }

    public function testPauseStopsReadsAndResumeKeepsTheCursor(): void
    {
        $this->status = ['status' => 'running', 'cursor' => '42', 'processed' => 2, 'index_cursor' => 0, 'indices' => [self::INDEX], 'paused' => true];
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::never())->method('structureRepairBatch');
        $migration = $this->service($metadata, $es);
        $migration->runSlice(1);
        $migration->control('resume');
        self::assertFalse($migration->getStatus()['paused']);
        self::assertSame('42', $this->status['cursor']);
    }

    public function testBulkFailureDoesNotAdvanceCursorAndCanRetryAfterWorkerRestart(): void
    {
        $this->status = ['status' => 'running', 'cursor' => '', 'processed' => 0, 'index_cursor' => 0, 'indices' => [self::INDEX], 'paused' => false];
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $metadata->method('metadata')->willReturn(['structure_schema' => 2, 'ancestor_ids' => [10], 'storage_numeric_id' => 1]);
        $attempt = 0;
        $es = $this->createMock(ElasticsearchClient::class);
        $es->method('updateStructureBatch')->willReturnCallback(static function () use (&$attempt): array {
            if (++$attempt === 1) { throw new ExternalServiceException('offline', 'elasticsearch_unreachable', true); }
            return [];
        });
        $migration = $this->service($metadata, $es);
        $migration->runSlice(1);
        self::assertSame('', $this->status['cursor']);
        self::assertSame('elasticsearch_unreachable', $this->errors[0]['last_error']);
        $migration->runSlice(1);
        self::assertSame('completed', $this->status['status']);
        self::assertSame([], $this->errors);
    }

    public function testIndividualErrorsBlockActivationAndExportOnlyThreeFields(): void
    {
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $metadata->method('metadata')->willReturn(['structure_schema' => 2, 'ancestor_ids' => [10], 'storage_numeric_id' => 1]);
        $metadata->method('find')->willReturn(['path' => 'files/photos/image.jpg']);
        $es = $this->createMock(ElasticsearchClient::class);
        $es->method('updateStructureBatch')->willReturn(['42' => 'version_conflict_engine_exception']);
        $migration = $this->service($metadata, $es);
        $migration->runSlice(1);
        self::assertSame('failed', $this->status['status']);
        $stream = $migration->errorExport();
        self::assertSame([['id' => 42, 'last_error' => 'version_conflict_engine_exception', 'storage_path' => 'files/photos/image.jpg']], json_decode(stream_get_contents($stream), true));
        fclose($stream);
        $this->expectException(ExternalServiceException::class);
        $migration->requireReady(self::INDEX);
    }

    public function testPendingRepairBlocksItsNewScopeButNotUnrelatedUsers(): void
    {
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('scopeContainsRepair')->willReturn(false);
        $pending = [['root_id' => 90, 'impact_roots' => '[90,10]', 'delete_subtree' => false]];
        $migration = $this->service($metadata, $es, ['42'], $pending);
        $migration->requireScopeReady(self::INDEX, ['bool' => ['should' => [['terms' => ['ancestor_ids' => [50]]]]]]);
        $this->expectException(ExternalServiceException::class);
        $migration->requireScopeReady(self::INDEX, ['bool' => ['should' => [['terms' => ['ancestor_ids' => [10]]]]]]);
    }

    public function testPendingRepairChecksOldEsScopeEvenWhenCurrentLocationIsPrivate(): void
    {
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('scopeContainsRepair')->willReturn(true);
        $migration = $this->service($metadata, $es, ['42'], [['root_id' => 90, 'impact_roots' => '[90,10]']]);
        $this->expectException(ExternalServiceException::class);
        $migration->requireScopeReady(self::INDEX, ['bool' => ['should' => [['terms' => ['ancestor_ids' => [50]]]]]]);
    }

    public function testPauseDuringABulkRequestSurvivesTheWorkerProgressWrite(): void
    {
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $metadata->method('metadata')->willReturn(['structure_schema' => 2, 'ancestor_ids' => [10], 'storage_numeric_id' => 1]);
        $es = $this->createMock(ElasticsearchClient::class);
        $migration = null;
        $es->expects(self::once())->method('updateStructureBatch')->willReturnCallback(static function () use (&$migration): array {
            $migration->control('pause');
            return [];
        });
        $migration = $this->service($metadata, $es);
        $migration->runSlice(1);
        self::assertTrue($migration->getStatus()['paused']);
        self::assertSame('42', $this->status['cursor']);
        $migration->control('resume');
        $migration->runSlice(1);
        self::assertSame('completed', $this->status['status']);
    }

    public function testConcurrentFileWriteKeepsCursorAndRetriesWithoutReportingFailure(): void
    {
        $metadata = $this->createMock(StructureMetadataRepository::class);
        $metadata->method('metadata')->willReturn(['structure_schema' => 2, 'ancestor_ids' => [10], 'storage_numeric_id' => 1]);
        $es = $this->createMock(ElasticsearchClient::class);
        $es->expects(self::once())->method('updateStructureBatch')->willReturn([]);
        $locks = $this->createMock(ILockingProvider::class);
        $blocked = false;
        $locks->method('acquireLock')->willReturnCallback(static function ($key) use (&$blocked): void {
            if (str_ends_with($key, ':file:42') && !$blocked) {
                $blocked = true;
                throw new \OCP\Lock\LockedException($key);
            }
        });
        $migration = $this->service($metadata, $es, ['42'], [], $locks);
        $migration->runSlice(1);
        self::assertSame('', $this->status['cursor']);
        self::assertSame([], $this->errors);
        $migration->runSlice(1);
        self::assertSame('completed', $this->status['status']);
    }

    private function service(StructureMetadataRepository $metadata, ElasticsearchClient $es, array $ids = ['42'], array $pending = [], ?ILockingProvider $locks = null): StructureMigrationService
    {
        $es->method('documentCount')->willReturn(count($ids));
        $es->method('structureRepairBatch')->willReturnCallback(static fn ($index, $root, $after): array => $after === '' ? $ids : []);
        $state = $this->createMock(StateRepository::class);
        $state->method('get')->willReturnCallback(fn ($key, $default = null) => $this->values[$key] ?? $default);
        $state->method('set')->willReturnCallback(function ($key, $value): void { $this->values[$key] = $value; });
        $state->method('getJson')->willReturnCallback(fn (): array => $this->status);
        $state->method('setJson')->willReturnCallback(function ($key, $status): void { $this->status = $status; });
        $tasks = $this->createMock(StructureTaskRepository::class);
        $tasks->method('pending')->willReturn($pending);
        $tasks->method('hasErrors')->willReturnCallback(fn (): bool => $this->errors !== []);
        $tasks->method('error')->willReturnCallback(function ($id, $error, $path): void { $this->errors[$id] = ['id' => $id, 'last_error' => $error, 'storage_path' => $path]; });
        $tasks->method('clearError')->willReturnCallback(function ($id): void { unset($this->errors[$id]); });
        $tasks->method('resetErrors')->willReturnCallback(function (): void { $this->errors = []; });
        $tasks->method('errors')->willReturnCallback(function (): \Generator { yield from $this->errors; });
        $indexed = $this->createMock(IndexedFileRepository::class);
        $indexed->method('find')->willReturn(['file_id' => 42]);
        $lifecycle = $this->createMock(IndexLifecycleService::class);
        $lifecycle->method('getStatus')->willReturn(['write_index' => self::INDEX, 'search_index' => self::INDEX]);
        return new StructureMigrationService($state, $metadata, $tasks, $indexed, $lifecycle, $es, $locks ?? $this->createMock(ILockingProvider::class), new NullLogger());
    }
}
