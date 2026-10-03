<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Manage;

use Magento\Framework\Exception\LocalizedException;
use Magento\Indexer\Model\Indexer;
use Panth\IndexerManager\Controller\Adminhtml\Manage\Run;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class RunTest extends ControllerTestCase
{
    private function controller(ReindexDispatcher $dispatcher): Run
    {
        $indexer = $this->createStub(Indexer::class);
        $indexer->method('getTitle')->willReturn('Product Price');
        return new Run($this->context(), $this->indexerFactory($indexer), $this->jsonFactory(), $dispatcher);
    }

    public function testMissingIndexerIdIsABadRequest(): void
    {
        $dispatcher = $this->createMock(ReindexDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $this->controller($dispatcher)->execute();

        $this->assertSame(400, $this->httpCode);
        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('Missing indexer_id.', $this->jsonData['message']);
    }

    public function testSyncRunReportsDuration(): void
    {
        $this->params = ['indexer_id' => 'catalog_product_price'];
        $dispatcher = $this->createMock(ReindexDispatcher::class);
        $dispatcher->expects($this->once())->method('dispatch')->with('catalog_product_price')
            ->willReturn(['mode' => 'sync', 'duration_ms' => 42]);

        $this->controller($dispatcher)->execute();

        $this->assertNull($this->httpCode);
        $this->assertTrue($this->jsonData['success']);
        $this->assertSame('sync', $this->jsonData['mode']);
        $this->assertSame(42, $this->jsonData['duration_ms']);
        $this->assertSame('Product Price reindexed in 42 ms.', $this->jsonData['message']);
    }

    public function testQueuedRunReportsQueuedMessage(): void
    {
        $this->params = ['indexer_id' => 'catalog_product_price'];
        $dispatcher = $this->createStub(ReindexDispatcher::class);
        $dispatcher->method('dispatch')->willReturn(['mode' => 'queued', 'duration_ms' => 0]);

        $this->controller($dispatcher)->execute();

        $this->assertSame('queued', $this->jsonData['mode']);
        $this->assertSame('Product Price queued for reindex.', $this->jsonData['message']);
    }

    public function testDispatcherFailureReturns500WithMessage(): void
    {
        $this->params = ['indexer_id' => 'catalog_product_price'];
        $dispatcher = $this->createStub(ReindexDispatcher::class);
        $dispatcher->method('dispatch')->willThrowException(new LocalizedException(__('already running')));

        $this->controller($dispatcher)->execute();

        $this->assertSame(500, $this->httpCode);
        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('catalog_product_price', $this->jsonData['indexer_id']);
        $this->assertSame('already running', $this->jsonData['message']);
    }
}
