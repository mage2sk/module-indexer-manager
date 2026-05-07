<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Plugin;

use Magento\Framework\Indexer\IndexerInterface;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\Model\Tracker;

class IndexerTrackingPlugin
{
    public function __construct(
        private readonly Tracker $tracker,
        private readonly Config $config
    ) {
    }

    public function aroundReindexAll(IndexerInterface $subject, callable $proceed)
    {
        return $this->wrap($subject, 'reindexAll', $proceed);
    }

    public function aroundReindexRow(IndexerInterface $subject, callable $proceed, $id)
    {
        return $this->wrap($subject, 'reindexRow', static fn () => $proceed($id));
    }

    public function aroundReindexList(IndexerInterface $subject, callable $proceed, array $ids)
    {
        return $this->wrap($subject, 'reindexList', static fn () => $proceed($ids));
    }

    private function wrap(IndexerInterface $subject, string $operation, callable $proceed)
    {
        if (!$this->config->isTrackingEnabled()) {
            return $proceed();
        }
        $indexerId = (string)$subject->getId();
        if ($indexerId === '') {
            return $proceed();
        }

        $log = $this->tracker->start($indexerId, $operation);
        $start = microtime(true);
        try {
            $result = $proceed();
            $this->tracker->finish($log, microtime(true) - $start);
            return $result;
        } catch (\Throwable $e) {
            $this->tracker->finish($log, microtime(true) - $start, $e->getMessage());
            throw $e;
        }
    }
}
