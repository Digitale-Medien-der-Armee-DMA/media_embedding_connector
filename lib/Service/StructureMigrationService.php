<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\{IndexedFileRepository, StateRepository, StructureMetadataRepository, StructureTaskRepository};
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\Lock\{ILockingProvider, LockedException};
use Psr\Log\LoggerInterface;

/** ScopeSearch: persistent, restartable metadata updates using existing vectors. */
class StructureMigrationService
{
    private const STATE = 'scope_search_migration';
    private const LOCK = 'media_embedding_connector:structure';
    private const PAUSED = 'scope_search_paused';
    private const RESTART = 'scope_search_restart';

    public function __construct(
        private StateRepository $state,
        private StructureMetadataRepository $metadata,
        private StructureTaskRepository $tasks,
        private IndexedFileRepository $indexed,
        private IndexLifecycleService $lifecycle,
        private ElasticsearchClient $es,
        private ILockingProvider $locks,
        private LoggerInterface $logger,
    ) {}

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        $status = $this->state->getJson(self::STATE) + ['status' => 'idle', 'schema_from' => 1,
            'schema_to' => 2, 'processed' => 0, 'total' => 0, 'paused' => false];
        $paused = $this->state->get(self::PAUSED);
        $status['paused'] = $paused === null ? (bool)$status['paused'] : $paused === '1';
        $token = $this->state->get(self::RESTART, '');
        $status['restart_pending'] = $token !== '' && $token !== null && $token !== ($status['restart_token'] ?? '');
        if ($status['restart_pending']) { $status['processed'] = 0; }
        $status['repair_pending'] = $this->tasks->next() !== null;
        $status['has_errors'] = $this->tasks->hasErrors();
        return $status;
    }

    public function requireReady(string $index): void
    {
        $status = $this->getStatus();
        if (($status['status'] ?? '') !== 'completed'
            || !in_array($index, $status['indices'] ?? [], true)) {
            throw new ExternalServiceException('Search index structure is being updated.', 'search_index_updating', true);
        }
    }

    /** Only users whose scopes overlap pending repairs wait. Unrelated users keep searching.
     * @param array<string, mixed> $permission
     */
    public function requireScopeReady(string $index, array $permission): void
    {
        $pending = $this->tasks->pending($index);
        if ($pending === [] || isset($permission['match_none'])) { return; }
        $roots = [];
        $files = [];
        foreach ($permission['bool']['should'] ?? [] as $clause) {
            array_push($roots, ...($clause['terms']['ancestor_ids'] ?? []));
            array_push($files, ...($clause['ids']['values'] ?? []));
        }
        $changed = [];
        foreach ($pending as $task) {
            $changed[] = (int)$task['root_id'];
            $impact = is_string($task['impact_roots'] ?? null) ? json_decode($task['impact_roots'], true) : null;
            if (!is_array($impact) || array_intersect($roots, $impact) !== []
                || in_array((string)$task['root_id'], $files, true)) {
                throw new ExternalServiceException('Search scope structure is being updated.', 'search_index_updating', true);
            }
        }
        $changed = array_values(array_unique($changed));
        // A user can have only a subfolder or a single file inside a moved tree.
        $this->metadata->resetCache();
        foreach (array_chunk(array_unique(array_merge($roots, array_map('intval', $files))), 200) as $chunk) {
            $this->metadata->prime($chunk);
            foreach ($chunk as $id) {
                $metadata = $this->metadata->metadata((int)$id);
                if (array_intersect(array_merge([(int)$id], $metadata['ancestor_ids'] ?? []), $changed) !== []) {
                    throw new ExternalServiceException('Search scope structure is being updated.', 'search_index_updating', true);
                }
            }
        }
        // Detect old locations still present in ES. This also protects previous
        // recipients when a tree was moved out of a shared parent.
        if ($this->es->scopeContainsRepair($index, $permission, $changed)) {
            throw new ExternalServiceException('Search scope structure is being updated.', 'search_index_updating', true);
        }
    }

    /** @return array<string, mixed> */
    public function control(string $action): array
    {
        if (!in_array($action, ['restart', 'pause', 'resume'], true)) {
            throw new \InvalidArgumentException('Unknown metadata migration action');
        }
        // Control keys are separate from worker progress. A worker cannot
        // overwrite a pause/restart request when it finishes an in-flight batch.
        $status = $this->getStatus();
        $this->state->set(self::PAUSED, $action === 'pause' ? '1' : '0');
        if ($action === 'restart' || ($action === 'resume' && in_array($status['status'], ['idle', 'failed'], true))) {
            $this->state->set(self::RESTART, bin2hex(random_bytes(16)));
        }
        return $this->getStatus();
    }

    /** @return array<string, mixed> */
    private function start(string $restartToken = ''): array
    {
        $indices = array_values(array_unique(array_filter([
            (string)($this->lifecycle->getStatus()['write_index'] ?? ''),
            (string)($this->lifecycle->getStatus()['search_index'] ?? ''),
        ])));
        $status = ['status' => 'running', 'schema_from' => 1, 'schema_to' => 2,
            'processed' => 0, 'total' => 0, 'index_cursor' => 0,
            'cursor' => '', 'paused' => false, 'indices' => [], 'restart_token' => $restartToken, 'started_at' => time(), 'updated_at' => time()];
        // Persist first, so failed mapping upgrades cannot leave a false ready flag.
        $this->state->setJson(self::STATE, $status);
        foreach ($indices as $index) {
            $this->es->ensureStructureMapping($index);
            $this->es->refreshIndex($index);
            $status['total'] += $this->es->documentCount($index);
        }
        $this->tasks->resetErrors();
        $status['indices'] = $indices;
        $this->state->setJson(self::STATE, $status);
        return $status;
    }

    /** Repair all descendants of a moved/deleted folder, or one moved file.
     * Current AND old index ancestry are handled; nested standard shares need no ACL copy.
     */
    public function enqueueRepair(int $rootId, bool $delete = false): void
    {
        if ($rootId <= 0) { return; }
        $indices = array_values(array_unique(array_filter([
            (string)($this->lifecycle->getStatus()['write_index'] ?? ''),
            (string)($this->lifecycle->getStatus()['search_index'] ?? ''),
        ])));
        $this->metadata->resetCache();
        try {
            $structure = $this->metadata->metadata($rootId);
            $impact = array_values(array_unique(array_merge([$rootId], $structure['ancestor_ids'] ?? [])));
        } catch (\Throwable) { $impact = null; }
        foreach ($indices as $index) { $this->tasks->enqueue($rootId, $index, $delete, $impact); }
    }

    /** Cron and the continuous worker share one cross-process lock.
     * Each lock covers one bounded batch; pause/restart can take effect between batches.
     */
    public function runSlice(int $seconds = 10): void
    {
        $deadline = microtime(true) + (float)max(1, $seconds);
        do {
            try { $this->locks->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE); }
            catch (LockedException) { return; }
            try {
                $status = $this->getStatus();
                if ($status['paused']) { return; }
                $configured = $this->lifecycle->getStatus();
                $write = (string)($configured['write_index'] ?? '');
                $search = (string)($configured['search_index'] ?? '');
                if ($write === '' && $search === '') { return; }
                if ($status['restart_pending'] || $status['status'] === 'idle' || ($status['status'] === 'running' && ($status['indices'] ?? []) === [])
                    || (($status['status'] ?? '') === 'completed' && array_diff(array_filter([$write, $search]), $status['indices'] ?? []) !== [])) {
                    $status = $this->start((string)$this->state->get(self::RESTART, ''));
                }
                $this->tasks->clearError(0);
                if ($status['status'] === 'running') {
                    $position = (int)($status['index_cursor'] ?? 0);
                    if ($position >= count($status['indices'])) {
                        $status['status'] = $this->tasks->hasErrors() ? 'failed' : 'completed';
                        $status['completed_at'] = time();
                    } else {
                        $index = (string)$status['indices'][$position];
                        // Scan the actual ES corpus, including old search-index documents
                        // and orphans that have no connector database row anymore.
                        $ids = $this->es->structureRepairBatch($index, 0, (string)$status['cursor']);
                        if ($ids === []) {
                            $this->es->refreshIndex($index);
                            $status['index_cursor'] = $position + 1;
                            $status['cursor'] = '';
                        } else {
                            if (!$this->update($index, $ids)) { return; }
                            $status['cursor'] = $ids[count($ids) - 1];
                            $status['processed'] += count($ids);
                        }
                    }
                    $status['updated_at'] = time();
                    $this->state->setJson(self::STATE, $status);
                } elseif ($status['status'] === 'failed') {
                    return;
                } else {
                    $task = $this->tasks->next();
                    if ($task === null) { return; }
                    $index = (string)$task['index_name'];
                    // A move can follow a fresh vector write before ES's normal
                    // refresh. Discover that vector before completing the task.
                    if ((string)$task['cursor_value'] === '') { $this->es->refreshIndex($index); }
                    $ids = $this->es->structureRepairBatch($index, (int)$task['root_id'], (string)$task['cursor_value']);
                    if ($ids === []) {
                        $this->es->refreshIndex($index);
                        $this->tasks->advance((int)$task['id'], null);
                    } else {
                        if (!$this->update($index, $ids, (bool)($task['delete_subtree'] ?? false))) { return; }
                        // Do not lose a failed repair: retries start again at the same cursor.
                        if ($this->tasks->hasErrors()) { return; }
                        $this->tasks->advance((int)$task['id'], $ids[count($ids) - 1]);
                    }
                }
                $this->tasks->clearError(0);
            } catch (\Throwable $e) {
                $code = $e instanceof ExternalServiceException ? $e->getPublicCode() : 'structure_update_failed';
                $this->tasks->error(0, $code, null);
                $this->logger->warning('ScopeSearch structure update failed', ['app' => 'media_embedding_connector', 'exception' => $e]);
                return;
            } finally { $this->locks->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE); }
        } while (microtime(true) < $deadline);
    }

    /** @param list<string> $ids */
    private function update(string $index, array $ids, bool $delete = false): bool
    {
        $held = [];
        sort($ids, SORT_NUMERIC);
        try {
            foreach ($ids as $id) {
                $lock = 'media_embedding_connector:structure:file:' . $id;
                $this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
                $held[] = $lock;
            }
            $this->metadata->resetCache();
            $this->metadata->prime(array_map('intval', $ids));
            $changes = [];
            foreach ($ids as $id) {
                try { $changes[$id] = $delete ? null : $this->metadata->metadata((int)$id); }
                catch (\Throwable) {
                    $this->tasks->error((int)$id, 'structure_ancestry_unavailable', $this->metadata->find((int)$id)['path'] ?? null, $index);
                }
            }
            $errors = $this->es->updateStructureBatch($index, $changes);
            foreach ($changes as $id => $metadata) {
                if (isset($errors[$id])) {
                    $this->tasks->error((int)$id, $errors[$id], $this->metadata->find((int)$id)['path'] ?? null, $index);
                } else {
                    $this->tasks->clearError((int)$id, $index);
                    if ($metadata === null) { $this->indexed->delete((string)$id); }
                }
            }
            return true;
        } catch (LockedException) {
            // A concurrent embedding write wins this slice. Keep the cursor so
            // its new vector cannot later receive stale migration metadata.
            return false;
        } finally {
            foreach (array_reverse($held) as $lock) { $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE); }
        }
    }

    /** @return resource */
    public function errorExport()
    {
        $stream = tmpfile();
        if ($stream === false) { throw new \RuntimeException('Cannot open metadata error export'); }
        try {
            fwrite($stream, "[\n");
            $first = true;
            foreach ($this->tasks->errors() as $row) {
                fwrite($stream, ($first ? '' : ",\n") . json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $first = false;
            }
            fwrite($stream, "\n]");
            rewind($stream);
            return $stream;
        } catch (\Throwable $e) { fclose($stream); throw $e; }
    }
}
