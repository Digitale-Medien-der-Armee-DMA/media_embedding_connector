<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Tests\Support;

use OCA\MediaEmbeddingConnector\Db\IndexJobRepository;
use OCP\IDBConnection;

class InMemoryIndexJobDatabase implements IDBConnection
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $lockCount = 0;
    public int $unlockCount = 0;
    private int $nextId = 1;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows = [])
    {
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $this->rows[$id] = $row;
            $this->nextId = max($this->nextId, $id + 1);
        }
    }

    public function getQueryBuilder(): InMemoryIndexJobQueryBuilder
    {
        return new InMemoryIndexJobQueryBuilder($this);
    }

    public function lockTable($tableName): void
    {
        self::assertTable($tableName);
        ++$this->lockCount;
    }

    public function unlockTable(): void
    {
        ++$this->unlockCount;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = ['id' => $id] + $row;
        return $id;
    }

    private static function assertTable(string $tableName): void
    {
        if ($tableName !== IndexJobRepository::TABLE) {
            throw new \RuntimeException('Unexpected table.');
        }
    }
}

class InMemoryIndexJobQueryBuilder
{
    private string $operation = '';
    /** @var array<string, mixed> */
    private array $values = [];
    /** @var list<array{0: string, 1: mixed}> */
    private array $conditions = [];
    /** @var list<array{0: string, 1: string}> */
    private array $orderBy = [];
    private ?int $limit = null;
    private int $lastInsertId = 0;
    private ?string $countAlias = null;
    /** @var list<string> */
    private array $groupBy = [];

    public function __construct(private InMemoryIndexJobDatabase $db)
    {
    }

    public function select(string ...$columns): self
    {
        $this->operation = 'select';
        return $this;
    }

    public function selectAlias(mixed $select, string $alias): self
    {
        $this->countAlias = $alias;
        return $this;
    }

    public function func(): object
    {
        return new class {
            public function count(string $field): array
            {
                return ['count' => $field];
            }
        };
    }

    public function groupBy(string $field): self
    {
        $this->groupBy = [$field];
        return $this;
    }

    public function from(string $table): self
    {
        return $this;
    }

    public function update(string $table): self
    {
        $this->operation = 'update';
        return $this;
    }

    public function insert(string $table): self
    {
        $this->operation = 'insert';
        return $this;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function values(array $values): self
    {
        $this->values = $values;
        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->values[$field] = $value;
        return $this;
    }

    public function where(array $condition): self
    {
        $this->conditions = [$condition];
        return $this;
    }

    public function andWhere(array $condition): self
    {
        $this->conditions[] = $condition;
        return $this;
    }

    public function orderBy(string $field, string $direction): self
    {
        $this->orderBy = [[$field, strtoupper($direction)]];
        return $this;
    }

    public function addOrderBy(string $field, string $direction): self
    {
        $this->orderBy[] = [$field, strtoupper($direction)];
        return $this;
    }

    public function setMaxResults(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function createNamedParameter(mixed $value, mixed $type = null): mixed
    {
        return $value;
    }

    public function expr(): InMemoryIndexJobExpression
    {
        return new InMemoryIndexJobExpression();
    }

    public function executeQuery(): InMemoryIndexJobResult
    {
        $rows = array_values(array_filter($this->db->rows, fn (array $row): bool => $this->matches($row)));
        if ($this->countAlias !== null && $this->groupBy !== []) {
            $field = $this->groupBy[0];
            $grouped = [];
            foreach ($rows as $row) {
                $key = (string)($row[$field] ?? '');
                $grouped[$key] = ($grouped[$key] ?? 0) + 1;
            }
            return new InMemoryIndexJobResult(array_map(
                fn (string $key, int $count): array => [$field => $key, $this->countAlias => $count],
                array_keys($grouped),
                array_values($grouped),
            ));
        }
        if ($this->countAlias !== null) {
            return new InMemoryIndexJobResult([[$this->countAlias => count($rows)]]);
        }
        if ($this->orderBy === []) {
            usort($rows, static fn (array $left, array $right): int => (int)$right['id'] <=> (int)$left['id']);
        } else {
            $orderBy = $this->orderBy;
            usort($rows, static function (array $left, array $right) use ($orderBy): int {
                foreach ($orderBy as [$field, $direction]) {
                    $comparison = ((int)($left[$field] ?? 0)) <=> ((int)($right[$field] ?? 0));
                    if ($comparison !== 0) {
                        return $direction === 'DESC' ? -$comparison : $comparison;
                    }
                }
                return 0;
            });
        }
        if ($this->limit !== null) {
            $rows = array_slice($rows, 0, $this->limit);
        }
        return new InMemoryIndexJobResult($rows);
    }

    public function executeStatement(): int
    {
        if ($this->operation === 'insert') {
            $this->lastInsertId = $this->db->insert($this->values);
            return 1;
        }

        $affected = 0;
        foreach ($this->db->rows as $id => $row) {
            if ($this->matches($row)) {
                foreach ($this->values as $field => $value) {
                    $this->db->rows[$id][$field] = $value;
                }
                ++$affected;
            }
        }
        return $affected;
    }

    public function getLastInsertId(): int
    {
        return $this->lastInsertId;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function matches(array $row): bool
    {
        foreach ($this->conditions as [$field, $operator, $value]) {
            $actual = $row[$field] ?? null;
            if ($operator === '=' && $actual != $value) {
                return false;
            }
            if ($operator === '<=' && $actual > $value) {
                return false;
            }
            if ($operator === '<' && $actual >= $value) {
                return false;
            }
            if ($operator === 'in' && (!is_array($value) || !in_array($actual, $value, true))) {
                return false;
            }
        }
        return true;
    }
}

class InMemoryIndexJobExpression
{
    /**
     * @return array{0: string, 1: string, 2: mixed}
     */
    public function eq(string $field, mixed $value): array
    {
        return [$field, '=', $value];
    }

    /**
     * @return array{0: string, 1: string, 2: mixed}
     */
    public function lte(string $field, mixed $value): array
    {
        return [$field, '<=', $value];
    }

    /**
     * @return array{0: string, 1: string, 2: mixed}
     */
    public function lt(string $field, mixed $value): array
    {
        return [$field, '<', $value];
    }

    /**
     * @return array{0: string, 1: string, 2: mixed}
     */
    public function in(string $field, mixed $value): array
    {
        return [$field, 'in', $value];
    }
}

class InMemoryIndexJobResult
{
    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    /**
     * @return array<string, mixed>|false
     */
    public function fetch(): array|false
    {
        return $this->rows[0] ?? false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(mixed $mode = null): array
    {
        if ($mode === \PDO::FETCH_COLUMN) {
            return array_map(static fn (array $row): mixed => reset($row), $this->rows);
        }
        return $this->rows;
    }

    public function fetchOne(): mixed
    {
        $first = $this->rows[0] ?? [];
        return is_array($first) ? reset($first) : null;
    }

    public function closeCursor(): void
    {
    }
}
