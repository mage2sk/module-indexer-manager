<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Routes a reindex request through the configured strategy:
 *  - "standard" (default): run reindexAll() synchronously in the request
 *  - "queue": publish to panth.indexer_manager.reindex topic; consumer picks it up
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Model\Queue;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Model\Config;

class ReindexDispatcher
{
    public const TOPIC = 'panth.indexer_manager.reindex';

    public function __construct(
        private readonly Config $config,
        private readonly IndexerFactory $indexerFactory,
        private readonly PublisherInterface $publisher
    ) {
    }

    /**
     * Returns one of:
     *   ['mode' => 'sync',   'duration_ms' => int]
     *   ['mode' => 'queued', 'duration_ms' => 0]
     */
    public function dispatch(string $indexerId): array
    {
        if ($this->config->getStrategy() === 'queue') {
            $this->publisher->publish(self::TOPIC, $indexerId);
            return ['mode' => 'queued', 'duration_ms' => 0];
        }

        $start = microtime(true);
        $indexer = $this->indexerFactory->create();
        $indexer->load($indexerId);
        $indexer->reindexAll();
        return ['mode' => 'sync', 'duration_ms' => (int)round((microtime(true) - $start) * 1000)];
    }
}
