<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCP\Files\Search\ISearchComparison;

/** Public filesystem query API; no dependency on Nextcloud's private query classes. */
final class FileIdComparison implements ISearchComparison
{
    /** @var array<string, mixed> */
    private array $hints = [];

    /** @param list<int> $fileIds */
    public function __construct(private array $fileIds) {}
    public function getType(): string { return self::COMPARE_IN; }
    public function getField(): string { return 'fileid'; }
    public function getExtra(): string { return ''; }
    /** @return list<int> */
    public function getValue(): array { return $this->fileIds; }
    public function getQueryHint(string $name, $default): mixed { return $this->hints[$name] ?? $default; }
    public function setQueryHint(string $name, $value): void { $this->hints[$name] = $value; }
}
