<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model\Queue;

use Panth\IndexerManager\Model\Queue\ReindexConsumer;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReindexConsumerTest extends TestCase
{
    public function testEmptyMessageIsIgnored(): void
    {
        $dispatcher = $this->createMock(ReindexDispatcher::class);
        $dispatcher->expects($this->never())->method('reindexNow');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        (new ReindexConsumer($dispatcher, $logger))->process('');
    }

    public function testSuccessfulReindexLogsNothing(): void
    {
        $dispatcher = $this->createMock(ReindexDispatcher::class);
        $dispatcher->expects($this->once())->method('reindexNow')->with('customer_grid')->willReturn(true);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');
        $logger->expects($this->never())->method('error');

        (new ReindexConsumer($dispatcher, $logger))->process('customer_grid');
    }

    public function testSkippedReindexIsLoggedAsInfo(): void
    {
        $dispatcher = $this->createStub(ReindexDispatcher::class);
        $dispatcher->method('reindexNow')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with(
            $this->stringContains('skipped'),
            ['indexer_id' => 'customer_grid']
        );

        (new ReindexConsumer($dispatcher, $logger))->process('customer_grid');
    }

    public function testFailureIsLoggedAndRethrown(): void
    {
        $error = new \RuntimeException('deadlock');
        $dispatcher = $this->createStub(ReindexDispatcher::class);
        $dispatcher->method('reindexNow')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            '[Panth IndexerManager] queue consumer failed: deadlock',
            ['indexer_id' => 'customer_grid']
        );

        $this->expectExceptionObject($error);
        (new ReindexConsumer($dispatcher, $logger))->process('customer_grid');
    }
}
