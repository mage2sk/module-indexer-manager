<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Cron;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\IndexerManager\Cron\CleanupRunLog;
use Panth\IndexerManager\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CleanupRunLogTest extends TestCase
{
    private function config(int $days): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getRetentionDays')->willReturn($days);
        return $config;
    }

    public function testZeroRetentionKeepsEverything(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        (new CleanupRunLog($this->config(0), $resource, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testNegativeRetentionKeepsEverything(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');

        (new CleanupRunLog($this->config(-3), $resource, $this->createStub(LoggerInterface::class)))->execute();
    }

    public function testDeletesRowsOlderThanRetentionAndLogsCount(): void
    {
        $captured = [];
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturnCallback(function ($table, $where) use (&$captured) {
            $captured = [$table, $where];
            return 4;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn($t) => 'pfx_' . $t);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')
            ->with('[Panth IndexerManager] cleanup removed 4 old log row(s).');

        (new CleanupRunLog($this->config(14), $resource, $logger))->execute();

        $this->assertSame('pfx_panth_indexer_manager_run_log', $captured[0]);
        $this->assertSame(['started_at < ?'], array_keys($captured[1]));
        $this->assertSame(
            'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 DAY)',
            (string)$captured[1]['started_at < ?']
        );
    }

    public function testNothingDeletedLogsNothing(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('delete')->willReturn(0);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        (new CleanupRunLog($this->config(7), $resource, $logger))->execute();
    }

    public function testDbErrorIsLoggedAsWarning(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('[Panth IndexerManager] cleanup failed: down');

        (new CleanupRunLog($this->config(7), $resource, $logger))->execute();
    }
}
