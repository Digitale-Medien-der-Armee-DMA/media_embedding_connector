<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Unit;

use OCA\MediaEmbeddingConnector\Db\VisibleFileCandidateRepository;
use OCA\MediaEmbeddingConnector\Exception\ExternalServiceException;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class VisibleFileCandidateRepositoryTest extends TestCase
{
    public function testSqlBoundsMountsDeduplicatesOverlapsAndKeepsSingleFileShares(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE filecache (fileid INTEGER PRIMARY KEY, storage INTEGER, path TEXT)');
        $insert = $pdo->prepare('INSERT INTO filecache VALUES (?, ?, ?)');
        $insert->execute([1, 8, 'files']);
        $insert->execute([2, 8, 'files/album']);
        for ($id = 10; $id <= 1012; ++$id) {
            $insert->execute([$id, 8, "files/album/photo-$id.jpg"]);
        }
        $insert->execute([2000, 8, 'files_trashbin/private.jpg']);
        $insert->execute([2001, 8, 'files-other/private.jpg']);
        $insert->execute([3000, 9, 'files/single-shared.jpg']);
        $insert->execute([3001, 9, 'files/private.jpg']);
        $insert->execute([4000, 9, 'files/100%_album']);
        $insert->execute([4001, 9, 'files/100%_album/shared.jpg']);
        $insert->execute([4002, 9, 'files/100XXalbum/private.jpg']);
        $batches = iterator_to_array($this->repository($pdo)->batches([1, 2, 1, 3000, 4000], 1000));
        $ids = array_merge(...$batches);
        self::assertGreaterThan(1, count($batches));
        self::assertLessThanOrEqual(1000, max(array_map('count', $batches)));
        self::assertSame(count($ids), count(array_unique($ids)));
        sort($ids);
        self::assertSame(array_merge([1, 2], range(10, 1012), [3000, 4000, 4001]), $ids);
    }

    public function testMissingMountedRootFailsInsteadOfReturningPartialCandidates(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE filecache (fileid INTEGER PRIMARY KEY, storage INTEGER, path TEXT)');
        $this->expectException(ExternalServiceException::class);
        iterator_to_array($this->repository($pdo)->batches([999], 1000));
    }

    private function repository(\PDO $pdo): VisibleFileCandidateRepository
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(static fn () => new CandidateSqlQuery($pdo));
        $db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string =>
            str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value));
        return new VisibleFileCandidateRepository($db);
    }
}

/** Executes production-generated query constraints against real SQLite. */
class CandidateSqlQuery
{
    private array $columns = [];
    private array $conditions = [];
    private string $table = '';
    private string $order = '';
    private int $limit = 0;
    public function __construct(private \PDO $pdo) {}
    public function select(string ...$columns): self { $this->columns = $columns; return $this; }
    public function from(string $table): self { $this->table = $table; return $this; }
    public function where(string $condition): self { $this->conditions = [$condition]; return $this; }
    public function andWhere(string $condition): self { $this->conditions[] = $condition; return $this; }
    public function orderBy(string $column, string $order): self { $this->order = "$column $order"; return $this; }
    public function setMaxResults(int $limit): self { $this->limit = $limit; return $this; }
    public function createNamedParameter(mixed $value, mixed $type = null): string {
        if (is_array($value)) { return implode(',', array_map('intval', $value)); }
        return is_int($value) ? (string)$value : $this->pdo->quote($value);
    }
    public function expr(): object { return new class {
        public function eq(string $a, string $b): string { return "$a = $b"; }
        public function gt(string $a, string $b): string { return "$a > $b"; }
        public function in(string $a, string $b): string { return "$a IN ($b)"; }
        public function like(string $a, string $b): string { return "$a LIKE $b ESCAPE '\\'"; }
        public function orX(string ...$conditions): string { return '(' . implode(' OR ', $conditions) . ')'; }
    }; }
    public function executeQuery(): object {
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' FROM ' . $this->table;
        if ($this->conditions !== []) { $sql .= ' WHERE ' . implode(' AND ', $this->conditions); }
        if ($this->order !== '') { $sql .= ' ORDER BY ' . $this->order; }
        if ($this->limit !== 0) { $sql .= ' LIMIT ' . $this->limit; }
        return new class($this->pdo->query($sql)) {
            public function __construct(private \PDOStatement $statement) {}
            public function fetchAllAssociative(): array { return $this->statement->fetchAll(\PDO::FETCH_ASSOC); }
            public function fetchFirstColumn(): array { return $this->statement->fetchAll(\PDO::FETCH_COLUMN); }
            public function closeCursor(): void { $this->statement->closeCursor(); }
        };
    }
}
