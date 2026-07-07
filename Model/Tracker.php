<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Panth\IndexerManager\Model\ResourceModel\RunLog as RunLogResource;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;
use Psr\Log\LoggerInterface;

class Tracker
{
    public function __construct(
        private readonly RunLogFactory $runLogFactory,
        private readonly RunLogResource $runLogResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime,
        private readonly Config $config,
        private readonly AppState $appState,
        private readonly AuthSession $authSession,
        private readonly LoggerInterface $logger,
        private readonly Notifier $notifier
    ) {
    }

    public function start(string $indexerId, string $operation = 'reindexAll'): ?RunLog
    {
        if (!$this->config->isTrackingEnabled()) {
            return null;
        }
        try {
            $log = $this->runLogFactory->create();
            $log->setData([
                'indexer_id' => $indexerId,
                'operation' => $operation,
                'context' => $this->resolveContext(),
                'status' => RunLog::STATUS_RUNNING,
                'started_at' => $this->dateTime->gmtDate(),
                'admin_user' => $this->resolveAdminUser(),
            ]);
            $this->runLogResource->save($log);
            return $log;
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth IndexerManager] start log failed: ' . $e->getMessage());
            return null;
        }
    }

    public function finish(?RunLog $log, float $durationSeconds, ?string $error = null): void
    {
        if ($log === null || !$log->getId()) {
            return;
        }
        try {
            $status = $error === null ? RunLog::STATUS_SUCCESS : RunLog::STATUS_ERROR;

            if ($error === null && $this->config->isFailuresOnly()) {
                $this->runLogResource->delete($log);
                return;
            }

            $log->setData('finished_at', $this->dateTime->gmtDate());
            $log->setData('duration_ms', (int)round($durationSeconds * 1000));
            $log->setData('status', $status);
            if ($error !== null) {
                $log->setData('message', mb_substr($error, 0, 4000));
            }
            $this->runLogResource->save($log);

            if ($error !== null) {
                $this->notifier->notifyFailure($log);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth IndexerManager] finish log failed: ' . $e->getMessage());
        }
    }

    public function getLatest(string $indexerId): ?RunLog
    {
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('indexer_id', $indexerId)
            ->setOrder('started_at', 'DESC')
            ->setPageSize(1);

        $first = $collection->getFirstItem();
        return $first && $first->getId() ? $first : null;
    }

    public function getLatestForAll(array $indexerIds): array
    {
        if (!$indexerIds) {
            return [];
        }
        $result = [];
        foreach ($indexerIds as $id) {
            $latest = $this->getLatest($id);
            if ($latest !== null) {
                $result[$id] = $latest;
            }
        }
        return $result;
    }

    private function resolveContext(): string
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Throwable) {
            return 'unknown';
        }
        return match ($area) {
            'adminhtml' => 'admin',
            'crontab' => 'cron',
            'webapi_rest', 'webapi_soap', 'graphql' => 'api',
            default => 'cli',
        };
    }

    private function resolveAdminUser(): ?string
    {
        try {
            $user = $this->authSession->getUser();
            return $user ? (string)$user->getUserName() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
