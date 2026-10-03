<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Manage;

use Panth\IndexerManager\Controller\Adminhtml\Manage\Status;
use Panth\IndexerManager\Model\Indexer\StateProvider;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class StatusTest extends ControllerTestCase
{
    public function testReturnsAllIndexerRows(): void
    {
        $provider = $this->createStub(StateProvider::class);
        $provider->method('getAll')->willReturn([['id' => 'a'], ['id' => 'b']]);

        (new Status($this->context(), $this->jsonFactory(), $provider))->execute();

        $this->assertSame(['success' => true, 'indexers' => [['id' => 'a'], ['id' => 'b']]], $this->jsonData);
        $this->assertNull($this->httpCode);
    }

    public function testProviderFailureReturns500(): void
    {
        $provider = $this->createStub(StateProvider::class);
        $provider->method('getAll')->willThrowException(new \RuntimeException('db down'));

        (new Status($this->context(), $this->jsonFactory(), $provider))->execute();

        $this->assertSame(500, $this->httpCode);
        $this->assertSame(['success' => false, 'message' => 'db down'], $this->jsonData);
    }
}
