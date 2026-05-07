<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Cron;

use Magento\Framework\App\ResourceConnection;
use Panth\IndexerManager\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Daily cron that prunes panth_indexer_manager_run_log entries older than the
 * configured retention window. retention_days = 0 means "keep forever".
 */
class CleanupRunLog
{
    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $days = $this->config->getRetentionDays();
        if ($days <= 0) {
            return;
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_indexer_manager_run_log');
            $deleted = $connection->delete(
                $table,
                ['started_at < ?' => new \Zend_Db_Expr(
                    'DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (int)$days . ' DAY)'
                )]
            );
            if ($deleted > 0) {
                $this->logger->info('[Panth IndexerManager] cleanup removed ' . $deleted . ' old log row(s).');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth IndexerManager] cleanup failed: ' . $e->getMessage());
        }
    }
}
