<?php
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
