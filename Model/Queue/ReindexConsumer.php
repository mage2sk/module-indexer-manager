<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model\Queue;

use Psr\Log\LoggerInterface;

class ReindexConsumer
{
    public function __construct(
        private readonly ReindexDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $indexerId): void
    {
        if ($indexerId === '') {
            return;
        }
        try {
            if (!$this->dispatcher->reindexNow($indexerId)) {
                $this->logger->info('[Panth IndexerManager] queue consumer skipped, reindex already running.', [
                    'indexer_id' => $indexerId,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[Panth IndexerManager] queue consumer failed: ' . $e->getMessage(), [
                'indexer_id' => $indexerId,
            ]);
            throw $e;
        }
    }
}
