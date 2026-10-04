<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Manage;

use Magento\Indexer\Model\Indexer;
use Panth\IndexerManager\Controller\Adminhtml\Manage\Mode;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ModeTest extends ControllerTestCase
{
    public static function badRequests(): array
    {
        return [
            'no id' => [['mode' => 'schedule']],
            'no mode' => [['indexer_id' => 'x']],
            'unknown mode' => [['indexer_id' => 'x', 'mode' => 'manual']],
        ];
    }

    #[DataProvider('badRequests')]
    public function testInvalidInputIsRejectedWithoutTouchingIndexer(array $params): void
    {
        $this->params = $params;
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->never())->method('setScheduled');

        (new Mode($this->context(), $this->indexerFactory($indexer), $this->jsonFactory()))->execute();

        $this->assertSame(400, $this->httpCode);
        $this->assertSame('Bad request.', $this->jsonData['message']);
    }

    public static function modes(): array
    {
        return [
            'schedule' => ['schedule', true, 'Price set to Update by Schedule.'],
            'realtime' => ['realtime', false, 'Price set to Update on Save.'],
        ];
    }

    #[DataProvider('modes')]
    public function testModeIsApplied(string $mode, bool $scheduled, string $message): void
    {
        $this->params = ['indexer_id' => 'catalog_product_price', 'mode' => $mode];
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())->method('load')->with('catalog_product_price');
        $indexer->expects($this->once())->method('setScheduled')->with($scheduled);
        $indexer->method('getTitle')->willReturn('Price');

        (new Mode($this->context(), $this->indexerFactory($indexer), $this->jsonFactory()))->execute();

        $this->assertTrue($this->jsonData['success']);
        $this->assertSame($mode, $this->jsonData['mode']);
        $this->assertSame($message, $this->jsonData['message']);
    }

    public function testFailureReturns500(): void
    {
        $this->params = ['indexer_id' => 'x', 'mode' => 'schedule'];
        $indexer = $this->createStub(Indexer::class);
        $indexer->method('setScheduled')->willThrowException(new \RuntimeException('no mview'));

        (new Mode($this->context(), $this->indexerFactory($indexer), $this->jsonFactory()))->execute();

        $this->assertSame(500, $this->httpCode);
        $this->assertSame('no mview', $this->jsonData['message']);
    }
}
