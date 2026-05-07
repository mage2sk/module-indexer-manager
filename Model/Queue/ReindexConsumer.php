<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Consumer for the deferred reindex strategy. Receives an indexer ID
 * (string) from the queue and runs reindexAll(). The tracking plugin
 * picks up the run automatically because it wraps IndexerInterface
 * regardless of who invokes it.
 */
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
            throw $e; // let queue mark as failed for retry
        }
    }
}
