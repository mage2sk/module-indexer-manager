<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model\Queue;

use Magento\Indexer\Model\IndexerFactory;
use Psr\Log\LoggerInterface;

class ReindexConsumer
{
    public function __construct(
        private readonly IndexerFactory $indexerFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $indexerId): void
    {
        if ($indexerId === '') {
            return;
        }
        try {
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerId);
            $indexer->reindexAll();
        } catch (\Throwable $e) {
            $this->logger->error('[Panth IndexerManager] queue consumer failed: ' . $e->getMessage(), [
                'indexer_id' => $indexerId,
            ]);
            throw $e;
        }
    }
}
