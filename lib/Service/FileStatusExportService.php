<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Service;

use OCA\MediaEmbeddingConnector\Db\FileStatusExportRepository;

class FileStatusExportService
{
    public function __construct(private FileStatusExportRepository $repository) {}

    /**
     * Spool to an automatically deleted temporary file, not PHP or browser RAM.
     * Finish generation before sending download headers so failures stay visible.
     *
     * @return resource
     */
    public function create(string $status)
    {
        if (!in_array($status, FileStatusExportRepository::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid export status.');
        }
        $stream = tmpfile();
        if ($stream === false) {
            throw new \RuntimeException('Export temporary file could not be created.');
        }
        try {
            $metadata = json_encode([
                'status' => $status,
                'generated_at' => gmdate('c'),
                'path_basis' => 'storage_relative',
                'consistency' => 'live_status_during_export',
            ], JSON_THROW_ON_ERROR);
            $this->write($stream, substr($metadata, 0, -1) . ",\"files\":[\n");
            $count = 0;
            foreach ($this->repository->rows($status) as $row) {
                $this->write($stream, ($count > 0 ? ",\n" : '')
                    . json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE));
                ++$count;
            }
            $this->write($stream, "\n],\"count\":" . $count . '}');
            rewind($stream);
            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    /** @param resource $stream */
    private function write($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Export could not be written.');
            }
            $offset += $written;
        }
    }
}
