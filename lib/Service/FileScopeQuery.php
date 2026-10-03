<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCP\Files\Search\ISearchQuery;
use OCP\IUser;

final class FileScopeQuery implements ISearchQuery
{
    /** @param list<int> $fileIds */
    public function __construct(private array $fileIds, private IUser $user) {}
    public function getSearchOperation(): FileIdComparison { return new FileIdComparison($this->fileIds); }
    // The bounded IN filter already limits distinct files. A SQL limit could
    // hide a readable mount behind another mount of the same file.
    public function getLimit(): int { return 0; }
    public function getOffset(): int { return 0; }
    /** @return list<FileIdOrder> */
    public function getOrder(): array { return [new FileIdOrder()]; }
    public function getUser(): IUser { return $this->user; }
    public function limitToHome(): bool { return false; }
    /** @return list<string> */
    public function getSelectFields(): array { return []; }
}
