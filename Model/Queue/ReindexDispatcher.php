<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model\Queue;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Model\Config;

class ReindexDispatcher
{
    public const TOPIC = 'panth.indexer_manager.reindex';

    private const LOCK_PREFIX = 'panth_im_reindex_';

    public function __construct(
        private readonly Config $config,
        private readonly IndexerFactory $indexerFactory,
        private readonly PublisherInterface $publisher,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    public function dispatch(string $indexerId): array
    {
        $indexer = $this->indexerFactory->create();
        $indexer->load($indexerId);
        $indexerId = (string)$indexer->getId();

        if ($this->config->isEnabled() && $this->config->getStrategy() === 'queue') {
            $this->publisher->publish(self::TOPIC, $indexerId);
            return ['mode' => 'queued', 'duration_ms' => 0];
        }

        $start = microtime(true);
        if (!$this->reindexNow($indexerId)) {
            throw new LocalizedException(__('%1 is already being reindexed.', $indexer->getTitle()));
        }
        return ['mode' => 'sync', 'duration_ms' => (int)round((microtime(true) - $start) * 1000)];
    }

    public function reindexNow(string $indexerId): bool
    {
        $lockName = self::LOCK_PREFIX . $indexerId;
        if (!$this->lockManager->lock($lockName, 0)) {
            return false;
        }
        try {
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerId);
            if ($indexer->isWorking()) {
                return false;
            }
            $indexer->reindexAll();
            return true;
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }
}
