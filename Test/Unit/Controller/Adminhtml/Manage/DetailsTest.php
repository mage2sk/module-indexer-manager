<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Manage;

use Panth\IndexerManager\Controller\Adminhtml\Manage\Details;
use Panth\IndexerManager\Model\Indexer\StateProvider;
use Panth\IndexerManager\Model\ResourceModel\RunLog\Collection;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;
use Panth\IndexerManager\Model\RunLog;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class DetailsTest extends ControllerTestCase
{
    /**
     * @var array
     */
    private array $calls = [];

    private function log(array $data): RunLog
    {
        $log = new class extends RunLog {
            public function __construct()
            {
            }
        };
        $log->setIdFieldName('log_id');
        $log->setData($data);
        return $log;
    }

    private function collectionFactory(array $logs): CollectionFactory
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($collection) {
            $this->calls[] = ['filter', $field, $value];
            return $collection;
        });
        $collection->method('setOrder')->willReturnCallback(function ($field, $dir) use ($collection) {
            $this->calls[] = ['order', $field, $dir];
            return $collection;
        });
        $collection->method('setPageSize')->willReturnCallback(function ($size) use ($collection) {
            $this->calls[] = ['size', $size];
            return $collection;
        });
        $collection->method('getIterator')->willReturn(new \ArrayIterator($logs));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    public function testMissingIdIsABadRequest(): void
    {
        $provider = $this->createMock(StateProvider::class);
        $provider->expects($this->never())->method('getOne');

        (new Details($this->context(), $this->jsonFactory(), $provider, $this->collectionFactory([])))->execute();

        $this->assertSame(400, $this->httpCode);
        $this->assertSame('Missing indexer_id.', $this->jsonData['message']);
    }

    public function testReturnsStateAndRecentRuns(): void
    {
        $this->params = ['indexer_id' => 'customer_grid'];
        $provider = $this->createStub(StateProvider::class);
        $provider->method('getOne')->willReturn(['id' => 'customer_grid']);
        $logs = [
            $this->log([
                'log_id' => '7', 'started_at' => 's', 'finished_at' => 'f', 'status' => 'error',
                'duration_ms' => '15', 'context' => 'cli', 'admin_user' => null, 'message' => 'oops',
            ]),
        ];

        (new Details($this->context(), $this->jsonFactory(), $provider, $this->collectionFactory($logs)))->execute();

        $this->assertTrue($this->jsonData['success']);
        $this->assertSame(['id' => 'customer_grid'], $this->jsonData['indexer']);
        $this->assertSame([
            'log_id' => 7, 'started_at' => 's', 'finished_at' => 'f', 'status' => 'error',
            'duration_ms' => 15, 'context' => 'cli', 'admin_user' => null, 'message' => 'oops',
        ], $this->jsonData['recent_runs'][0]);
        $this->assertSame(
            [['filter', 'indexer_id', 'customer_grid'], ['order', 'started_at', 'DESC'], ['size', 10]],
            $this->calls
        );
    }

    public function testFailureReturns500(): void
    {
        $this->params = ['indexer_id' => 'customer_grid'];
        $provider = $this->createStub(StateProvider::class);
        $provider->method('getOne')->willThrowException(new \RuntimeException('gone'));

        (new Details($this->context(), $this->jsonFactory(), $provider, $this->collectionFactory([])))->execute();

        $this->assertSame(500, $this->httpCode);
        $this->assertSame('gone', $this->jsonData['message']);
    }
}
