<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Log;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;
use Panth\IndexerManager\Model\RunLog;

class Listing extends Template
{
    protected $_template = 'Panth_IndexerManager::log/list.phtml';

    public const PAGE_SIZE = 10;

    private ?\Panth\IndexerManager\Model\ResourceModel\RunLog\Collection $collection = null;

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    private function getCollection(): \Panth\IndexerManager\Model\ResourceModel\RunLog\Collection
    {
        if ($this->collection === null) {
            $this->collection = $this->collectionFactory->create()
                ->setOrder('started_at', 'DESC')
                ->setPageSize(self::PAGE_SIZE)
                ->setCurPage($this->getCurrentPage());
        }
        return $this->collection;
    }

    public function getEntries(): array
    {
        $rows = [];
        /** @var RunLog $entry */
        foreach ($this->getCollection() as $entry) {
            $rows[] = [
                'log_id' => (int)$entry->getId(),
                'indexer_id' => $entry->getData('indexer_id'),
                'operation' => $entry->getData('operation'),
                'context' => $entry->getData('context'),
                'status' => $entry->getData('status'),
                'started_at' => $entry->getData('started_at'),
                'finished_at' => $entry->getData('finished_at'),
                'duration_ms' => (int)$entry->getData('duration_ms'),
                'admin_user' => $entry->getData('admin_user'),
                'message' => $entry->getData('message'),
            ];
        }
        return $rows;
    }

    public function getTotalCount(): int
    {
        return (int)$this->getCollection()->getSize();
    }

    public function getCurrentPage(): int
    {
        return max(1, (int)$this->_request->getParam('p', 1));
    }

    public function getLastPage(): int
    {
        return (int)max(1, (int)$this->getCollection()->getLastPageNumber());
    }

    public function getPageUrl(int $page): string
    {
        return $this->getUrl('panth_indexer_manager/log/index', ['p' => max(1, $page)]);
    }

    public function getClearUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/log/clear');
    }

    public function getIndexManagementUrl(): string
    {
        return $this->getUrl('indexer/indexer/list');
    }

    public function getStatusBadge(string $status): array
    {
        return match ($status) {
            RunLog::STATUS_SUCCESS => ['class' => 'grid-severity-notice', 'label' => __('Success')->render()],
            RunLog::STATUS_ERROR => ['class' => 'grid-severity-critical', 'label' => __('Error')->render()],
            RunLog::STATUS_RUNNING => ['class' => 'grid-severity-minor', 'label' => __('Running')->render()],
            default => ['class' => '', 'label' => ucfirst($status)],
        };
    }

    public function formatDuration(int $ms): string
    {
        if ($ms <= 0) {
            return '—';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        $seconds = $ms / 1000;
        if ($seconds < 60) {
            return number_format($seconds, 2) . ' s';
        }
        $minutes = floor($seconds / 60);
        $rem = round($seconds - $minutes * 60);
        return $minutes . 'm ' . $rem . 's';
    }

    public function getPageRange(int $current, int $last, int $window = 5): array
    {
        $half = (int)floor($window / 2);
        $start = max(1, $current - $half);
        $end = min($last, $start + $window - 1);
        $start = max(1, $end - $window + 1);
        return range($start, $end);
    }
}
