<?php

declare(strict_types=1);
namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\FileStatusExportRepository;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FileStatusExportRepositoryTest extends TestCase
{
    public function testRealSqlExportsAllPagesForEachStatusAndKeepsMissingFiles(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE media_embed_idx_jobs (id INTEGER PRIMARY KEY, file_id INTEGER, status TEXT, last_error TEXT)');
        $pdo->exec('CREATE TABLE media_embed_skips (id INTEGER PRIMARY KEY, file_id INTEGER, reason TEXT)');
        $pdo->exec('CREATE TABLE filecache (fileid INTEGER PRIMARY KEY, name TEXT, path TEXT, storage INTEGER)');
        $jobs = $pdo->prepare('INSERT INTO media_embed_idx_jobs VALUES (?, ?, ?, ?)');
        $skips = $pdo->prepare('INSERT INTO media_embed_skips VALUES (?, ?, ?)');
        $files = $pdo->prepare('INSERT INTO filecache VALUES (?, ?, ?, ?)');
        $statuses = ['queued', 'running', 'failed'];
        for ($id = 1; $id <= 23001; ++$id) {
            $jobs->execute([$id, $id, $statuses[($id - 1) % 3], 'diagnostic']);
            $skips->execute([$id, $id, 'invalid_image']);
            if ($id !== 1) { $files->execute([$id, "photo-$id.jpg", "files/photo-$id.jpg", 8]); }
        }
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(static fn () => new ExportSqlQuery($pdo));
        $repository = new FileStatusExportRepository($db);
        foreach (FileStatusExportRepository::STATUSES as $status) {
            $rows = iterator_to_array($repository->rows($status));
            self::assertCount($status === 'skipped' ? 23001 : 7667, $rows);
            $ids = array_column($rows, 'id');
            self::assertSame(count($ids), count(array_unique($ids)));
            self::assertGreaterThan(22000, max($ids));
            if ($status === 'queued' || $status === 'skipped') {
                self::assertSame(1, $rows[0]['file_id']);
                self::assertNull($rows[0]['file_name']);
                self::assertNull($rows[0]['storage_path']);
            }
            self::assertSame(8, $rows[1]['storage_numeric_id']);
            if ($status !== 'skipped') {
                self::assertSame([$status], array_values(array_unique(array_column($rows, 'status'))));
                self::assertSame('diagnostic', $rows[1]['last_error']);
            } else { self::assertSame('invalid_image', $rows[1]['reason']); }
        }
    }
}

/** Minimal adapter executes the repository's generated SQL against real SQLite. */
class ExportSqlQuery
{
    private array $columns = [];
    private string $table = '';
    private array $joins = [];
    private array $conditions = [];
    private string $order = '';
    private int $limit = 0;
    public function __construct(private \PDO $pdo) {}
    public function select(string ...$columns): self { $this->columns = $columns; return $this; }
    public function selectAlias(string $column, string $alias): self { $this->columns[] = "$column AS $alias"; return $this; }
    public function from(string $table, string $alias = ''): self { $this->table = "$table $alias"; return $this; }
    public function leftJoin(string $from, string $table, string $alias, string $on): self { $this->joins[] = "LEFT JOIN $table $alias ON $on"; return $this; }
    public function where(string $condition): self { $this->conditions = [$condition]; return $this; }
    public function andWhere(string $condition): self { $this->conditions[] = $condition; return $this; }
    public function orderBy(string $column, string $order): self { $this->order = "$column $order"; return $this; }
    public function setMaxResults(int $limit): self { $this->limit = $limit; return $this; }
    public function createNamedParameter(mixed $value, mixed $type = null): string { return is_int($value) ? (string)$value : $this->pdo->quote($value); }
    public function expr(): object { return new class {
        public function eq(string $a, string $b): string { return "$a = $b"; }
        public function gt(string $a, string $b): string { return "$a > $b"; }
        public function lte(string $a, string $b): string { return "$a <= $b"; }
    }; }
    public function executeQuery(): object
    {
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table . ' ' . implode(' ', $this->joins);
        if ($this->conditions !== []) { $sql .= ' WHERE ' . implode(' AND ', $this->conditions); }
        $sql .= ' ORDER BY ' . $this->order . ' LIMIT ' . $this->limit;
        return new class($this->pdo->query($sql)) {
            public function __construct(private \PDOStatement $result) {}
            public function fetchAssociative(): array|false { return $this->result->fetch(\PDO::FETCH_ASSOC); }
            public function fetchAllAssociative(): array { return $this->result->fetchAll(\PDO::FETCH_ASSOC); }
            public function closeCursor(): void { $this->result->closeCursor(); }
        };
    }
}
