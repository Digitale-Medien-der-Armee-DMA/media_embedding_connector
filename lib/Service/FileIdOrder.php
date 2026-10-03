<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCP\Files\FileInfo;
use OCP\Files\Search\ISearchOrder;

final class FileIdOrder implements ISearchOrder
{
    public function getDirection(): string { return self::DIRECTION_ASCENDING; }
    public function getField(): string { return 'fileid'; }
    public function getExtra(): string { return ''; }
    public function sortFileInfo(FileInfo $a, FileInfo $b): int { return $a->getId() <=> $b->getId(); }
}
