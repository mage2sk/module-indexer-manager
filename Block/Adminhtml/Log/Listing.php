<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Log;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Panth\IndexerManager\Model\DateFormatter;
use Panth\IndexerManager\Model\ResourceModel\RunLog\Collection;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;
use Panth\IndexerManager\Model\RunLog;

class Listing extends Template
{
    protected $_template = 'Panth_IndexerManager::log/list.phtml';

    public const PAGE_SIZE = 10;

    public const DEFAULT_SORT = 'started_at';

    public const SORTABLE = [
        'started_at' => 'DESC',
        'indexer_id' => 'ASC',
        'operation' => 'ASC',
        'context' => 'ASC',
        'status' => 'ASC',
        'duration_ms' => 'DESC',
        'admin_user' => 'ASC',
    ];

    public const STATUSES = [RunLog::STATUS_SUCCESS, RunLog::STATUS_ERROR, RunLog::STATUS_RUNNING];

    public const CONTEXTS = ['admin', 'cron', 'api', 'cli', 'unknown'];

    private ?Collection $collection = null;

    private ?array $filters = null;

    private ?array $indexerOptions = null;

    public function __construct(
        Context $context,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateFormatter $dateFormatter,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    private function getCollection(): Collection
    {
        if ($this->collection === null) {
            $filters = $this->getFilters();
            $collection = $this->collectionFactory->create();
            if ($filters['q'] !== '') {
                $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
                $collection->addFieldToFilter(
                    ['indexer_id', 'message', 'admin_user'],
                    [['like' => $like], ['like' => $like], ['like' => $like]]
                );
            }
            foreach (['indexer' => 'indexer_id', 'status' => 'status', 'context' => 'context'] as $key => $field) {
                if ($filters[$key] !== '') {
                    $collection->addFieldToFilter($field, $filters[$key]);
                }
            }
            $collection->setOrder($this->getSortField(), $this->getSortDirection());
            $collection->setOrder('log_id', $this->getSortDirection());
            $collection->setPageSize(self::PAGE_SIZE);
            $lastPage = max(1, (int)ceil((int)$collection->getSize() / self::PAGE_SIZE));
            $collection->setCurPage(min($this->getCurrentPage(), $lastPage));
            $this->collection = $collection;
        }
        return $this->collection;
    }

    public function getFilters(): array
    {
        if ($this->filters === null) {
            $q = trim((string)$this->_request->getParam('q', ''));
            $indexer = trim((string)$this->_request->getParam('indexer', ''));
            $status = (string)$this->_request->getParam('status', '');
            $context = (string)$this->_request->getParam('context', '');
            $this->filters = [
                'q' => mb_substr($q, 0, 100),
                'indexer' => preg_match('/^[A-Za-z0-9_]{1,64}$/', $indexer) ? $indexer : '',
                'status' => in_array($status, self::STATUSES, true) ? $status : '',
                'context' => in_array($context, self::CONTEXTS, true) ? $context : '',
            ];
        }
        return $this->filters;
    }

    public function hasActiveFilters(): bool
    {
        return $this->getFilterParams() !== [];
    }

    public function getSortField(): string
    {
        $sort = (string)$this->_request->getParam('sort', '');
        return array_key_exists($sort, self::SORTABLE) ? $sort : self::DEFAULT_SORT;
    }

    public function getSortDirection(): string
    {
        $dir = strtoupper((string)$this->_request->getParam('dir', ''));
        if ($dir === 'ASC' || $dir === 'DESC') {
            return $dir;
        }
        return self::SORTABLE[$this->getSortField()];
    }

    public function getSortUrl(string $field): string
    {
        if (!array_key_exists($field, self::SORTABLE)) {
            $field = self::DEFAULT_SORT;
        }
        if ($field === $this->getSortField()) {
            $dir = $this->getSortDirection() === 'ASC' ? 'desc' : 'asc';
        } else {
            $dir = strtolower(self::SORTABLE[$field]);
        }
        $params = $this->getFilterParams();
        $params['sort'] = $field;
        $params['dir'] = $dir;
        return $this->getUrl('panth_indexer_manager/log/index', ['_query' => $params]);
    }

    public function getAriaSort(string $field): string
    {
        if ($field !== $this->getSortField()) {
            return 'none';
        }
        return $this->getSortDirection() === 'ASC' ? 'ascending' : 'descending';
    }

    private function getFilterParams(): array
    {
        return array_filter($this->getFilters(), static fn($v) => $v !== '');
    }

    private function getStateParams(): array
    {
        $params = $this->getFilterParams();
        $sort = (string)$this->_request->getParam('sort', '');
        if (array_key_exists($sort, self::SORTABLE)) {
            $params['sort'] = $sort;
            $params['dir'] = strtolower($this->getSortDirection());
        }
        return $params;
    }

    public function getEntries(): array
    {
        $rows = [];

        foreach ($this->getCollection() as $entry) {
            $rows[] = [
                'log_id' => (int)$entry->getId(),
                'indexer_id' => $entry->getData('indexer_id'),
                'operation' => $entry->getData('operation'),
                'context' => $entry->getData('context'),
                'status' => $entry->getData('status'),
                'started_at' => $this->dateFormatter->format((string)$entry->getData('started_at')),
                'finished_at' => $this->dateFormatter->format((string)$entry->getData('finished_at')),
                'duration_ms' => (int)$entry->getData('duration_ms'),
                'admin_user' => $entry->getData('admin_user'),
                'message' => $entry->getData('message'),
            ];
        }
        return $rows;
    }

    public function getIndexerOptions(): array
    {
        if ($this->indexerOptions === null) {
            $options = [];
            try {
                $collection = $this->collectionFactory->create();
                $connection = $collection->getConnection();
                $select = $connection->select()
                    ->distinct(true)
                    ->from($collection->getMainTable(), ['indexer_id'])
                    ->order('indexer_id ASC');
                foreach ($connection->fetchCol($select) as $id) {
                    $options[] = (string)$id;
                }
            } catch (\Throwable) {
                $options = [];
            }
            $current = $this->getFilters()['indexer'];
            if ($current !== '' && !in_array($current, $options, true)) {
                $options[] = $current;
            }
            $this->indexerOptions = $options;
        }
        return $this->indexerOptions;
    }

    public function getTotalCount(): int
    {
        return (int)$this->getCollection()->getSize();
    }

    public function getCurrentPage(): int
    {
        return max(1, (int)$this->_request->getParam('p', 1));
    }

    public function getEffectivePage(): int
    {
        return min($this->getCurrentPage(), $this->getLastPage());
    }

    public function getLastPage(): int
    {
        return (int)max(1, (int)$this->getCollection()->getLastPageNumber());
    }

    public function getPageUrl(int $page): string
    {
        $params = $this->getStateParams();
        $params['p'] = max(1, $page);
        return $this->getUrl('panth_indexer_manager/log/index', ['_query' => $params]);
    }

    public function getFilterUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/log/index');
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
            return '-';
        }
        if ($ms < 1000) {
            return $ms . ' ms';
        }
        if ($ms < 59995) {
            return number_format($ms / 1000, 2) . ' s';
        }
        $totalSeconds = (int)round($ms / 1000);
        return intdiv($totalSeconds, 60) . 'm ' . ($totalSeconds % 60) . 's';
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
