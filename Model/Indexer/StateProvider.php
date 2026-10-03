<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Model\Tracker;

class StateProvider
{
    public const BACKLOG_CAP = 10000;

    public function __construct(
        private readonly IndexerCollectionFactory $indexerCollectionFactory,
        private readonly IndexerFactory $indexerFactory,
        private readonly Tracker $tracker,
        private readonly ResourceConnection $resource
    ) {
    }

    public function getAll(): array
    {
        $collection = $this->indexerCollectionFactory->create();
        $rows = [];
        $ids = [];

        foreach ($collection->getItems() as $indexer) {
            $ids[] = (string)$indexer->getId();
        }

        $latestMap = $this->tracker->getLatestForAll($ids);

        foreach ($collection->getItems() as $indexer) {
            $rows[] = $this->buildRow($indexer, $latestMap[(string)$indexer->getId()] ?? null);
        }
        return $rows;
    }

    public function getOne(string $indexerId): array
    {
        $indexer = $this->indexerFactory->create();
        $indexer->load($indexerId);
        return $this->buildRow($indexer, $this->tracker->getLatest($indexerId));
    }

    private function buildRow(IndexerInterface $indexer, $latestRun): array
    {
        $status = (string)$indexer->getStatus();
        $isScheduled = (bool)$indexer->isScheduled();
        $schedule = $this->buildSchedule($indexer);

        return [
            'id' => (string)$indexer->getId(),
            'title' => (string)$indexer->getTitle(),
            'description' => (string)$indexer->getDescription(),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_class' => $this->statusClass($status),
            'is_working' => $status === StateInterface::STATUS_WORKING,
            'mode' => $isScheduled ? 'schedule' : 'realtime',
            'mode_label' => $isScheduled ? __('Update by Schedule')->render() : __('Update on Save')->render(),
            'updated' => (string)$indexer->getLatestUpdated(),
            'schedule' => $schedule,
            'latest_run' => $latestRun ? [
                'log_id' => (int)$latestRun->getId(),
                'started_at' => $latestRun->getData('started_at'),
                'finished_at' => $latestRun->getData('finished_at'),
                'duration_ms' => (int)$latestRun->getData('duration_ms'),
                'status' => $latestRun->getData('status'),
                'context' => $latestRun->getData('context'),
                'admin_user' => $latestRun->getData('admin_user'),
                'message' => $latestRun->getData('message'),
            ] : null,
        ];
    }

    private function buildSchedule(IndexerInterface $indexer): array
    {
        $payload = [
            'available' => false,
            'status' => null,
            'backlog' => 0,
            'backlog_capped' => false,
            'class' => 'grid-severity-notice',
            'label' => '',
        ];

        if (!$indexer->isScheduled()) {
            return $payload;
        }
        try {
            $view = $indexer->getView();
            if (!$view->getId()) {
                return $payload;
            }
            $state = $view->getState()->loadByView($view->getId());
            $changelog = $view->getChangelog()->setViewId($view->getId());
            $count = $this->countBacklog(
                (string)$changelog->getName(),
                (string)$changelog->getColumnName(),
                (int)$state->getVersionId()
            );
            $capped = $count > self::BACKLOG_CAP;
            $count = min($count, self::BACKLOG_CAP);
            $payload['available'] = true;
            $payload['status'] = (string)$state->getStatus();
            $payload['backlog'] = $count;
            $payload['backlog_capped'] = $capped;
            $payload['class'] = $this->backlogClass($count, $payload['status']);
            $payload['label'] = sprintf(
                '%s (%s in backlog)',
                strtoupper((string)$state->getStatus()),
                $capped ? $count . '+' : (string)$count
            );
        } catch (\Throwable) {
            $payload['label'] = strtoupper(__('Unavailable')->render());
            $payload['class'] = 'grid-severity-minor';
        }
        return $payload;
    }

    private function countBacklog(string $changelogName, string $column, int $fromVersion): int
    {
        $connection = $this->resource->getConnection();
        $distinct = $connection->select()
            ->distinct(true)
            ->from($this->resource->getTableName($changelogName), [$column])
            ->where('version_id > ?', $fromVersion)
            ->limit(self::BACKLOG_CAP + 1);
        $select = $connection->select()->from(['backlog' => $distinct], ['cnt' => new \Zend_Db_Expr('COUNT(*)')]);
        return (int)$connection->fetchOne($select);
    }

    private function statusClass(string $status): string
    {
        return match ($status) {
            StateInterface::STATUS_VALID => 'grid-severity-notice',
            StateInterface::STATUS_INVALID => 'grid-severity-critical',
            StateInterface::STATUS_WORKING => 'grid-severity-minor',
            StateInterface::STATUS_SUSPENDED => 'grid-severity-minor',
            default => '',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            StateInterface::STATUS_VALID => __('Ready')->render(),
            StateInterface::STATUS_INVALID => __('Reindex required')->render(),
            StateInterface::STATUS_WORKING => __('Processing')->render(),
            StateInterface::STATUS_SUSPENDED => __('Suspended')->render(),
            default => ucfirst($status),
        };
    }

    private function backlogClass(int $count, string $stateStatus): string
    {
        if ($stateStatus !== 'idle') {
            return 'grid-severity-minor';
        }
        return match (true) {
            $count > 1000 => 'grid-severity-critical',
            $count > 100 => 'grid-severity-major',
            $count > 10 => 'grid-severity-minor',
            default => 'grid-severity-notice',
        };
    }
}
