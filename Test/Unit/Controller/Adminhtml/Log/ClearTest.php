<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Log;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Panth\IndexerManager\Controller\Adminhtml\Log\Clear;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class ClearTest extends ControllerTestCase
{
    public function testDeletesAllRunLogRowsAndRedirects(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('delete')->with('pfx_panth_indexer_manager_run_log');
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn($t) => 'pfx_' . $t);

        (new Clear($this->context(), $resource))->execute();

        $this->assertSame(['Run log cleared.'], $this->messagesOf('success'));
        $this->assertSame('panth_indexer_manager/log/index', $this->redirectPath);
    }

    public function testDbErrorIsReportedAndStillRedirects(): void
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willThrowException(new \RuntimeException('locked'));

        (new Clear($this->context(), $resource))->execute();

        $this->assertSame(['locked'], $this->messagesOf('error'));
        $this->assertSame([], $this->messagesOf('success'));
        $this->assertSame('panth_indexer_manager/log/index', $this->redirectPath);
    }
}
