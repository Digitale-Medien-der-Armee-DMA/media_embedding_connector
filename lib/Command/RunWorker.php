<?php

declare(strict_types=1);

namespace OCA\MediaEmbeddingConnector\Command;

use OCA\MediaEmbeddingConnector\Service\AppConfig;
use OCA\MediaEmbeddingConnector\Service\BackfillScanner;
use OCA\MediaEmbeddingConnector\Service\ImageEmbeddingQueueWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Continuous worker for large indexing runs, meant to be kept alive by a
 * process supervisor such as systemd. It keeps the image queue busy without
 * waiting for the next cron run and advances the backfill scan whenever the
 * queue needs more work. The cron jobs keep running next to it; the
 * lock-free claim prevents duplicate processing.
 */
class RunWorker extends Command
{
    private const SCAN_SLICE_SECONDS = 10;

    public function __construct(
        private ImageEmbeddingQueueWorker $worker,
        private BackfillScanner $scanner,
        private AppConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('media_embedding_connector:worker')
            ->setDescription('Continuously process queued image embeddings and advance the backfill scan')
            ->addOption(
                'time-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Exit after this many seconds so the supervisor can restart a fresh process (0 = no limit)',
                '3600',
            )
            ->addOption(
                'idle-sleep',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds to wait when no image is ready',
                '5',
            )
            ->addOption('no-scan', null, InputOption::VALUE_NONE, 'Only process the queue; leave the backfill scan to cron');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $timeLimit = max(0, (int)$input->getOption('time-limit'));
        $idleSleep = max(1, min(300, (int)$input->getOption('idle-sleep')));
        $scan = !(bool)$input->getOption('no-scan');
        $deadline = $timeLimit > 0 ? microtime(true) + (float)$timeLimit : null;
        $totalItems = 0;

        while ($deadline === null || microtime(true) < $deadline) {
            $this->config->reload();
            if (!$this->config->isIndexingEnabled()) {
                $output->writeln('<comment>Indexing is disabled; waiting.</comment>', OutputInterface::VERBOSITY_VERBOSE);
                sleep($idleSleep);
                continue;
            }

            if ($scan) {
                $this->scanner->runSlice(self::SCAN_SLICE_SECONDS);
            }

            $remaining = $deadline === null ? 60 : (int)max(1, min(60, $deadline - microtime(true)));
            $stats = $this->worker->drain($remaining);
            $totalItems += $stats['items'];
            if ($stats['items'] > 0) {
                $output->writeln(sprintf(
                    'Processed %d images in %d batches (%d total).',
                    $stats['items'],
                    $stats['batches'],
                    $totalItems,
                ), OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }
            sleep($idleSleep);
        }

        $output->writeln(sprintf('Time limit reached after %d processed images.', $totalItems));
        return 0;
    }
}
