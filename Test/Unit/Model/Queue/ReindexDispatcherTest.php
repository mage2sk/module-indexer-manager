<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model\Queue;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;
use PHPUnit\Framework\TestCase;

class ReindexDispatcherTest extends TestCase
{
    /**
     * @var array
     */
    private array $lockCalls = [];

    private function dispatcher(
        Indexer $indexer,
        string $strategy = 'standard',
        bool $lockAcquired = true,
        ?PublisherInterface $publisher = null,
        bool $enabled = true
    ): ReindexDispatcher {
        $config = $this->createStub(Config::class);
        $config->method('getStrategy')->willReturn($strategy);
        $config->method('isEnabled')->willReturn($enabled);
        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('create')->willReturn($indexer);
        $lock = $this->createStub(LockManagerInterface::class);
        $lock->method('lock')->willReturnCallback(function ($name, $timeout) use ($lockAcquired) {
            $this->lockCalls[] = ['lock', $name, $timeout];
            return $lockAcquired;
        });
        $lock->method('unlock')->willReturnCallback(function ($name) {
            $this->lockCalls[] = ['unlock', $name];
            return true;
        });
        return new ReindexDispatcher(
            $config,
            $factory,
            $publisher ?? $this->createStub(PublisherInterface::class),
            $lock
        );
    }

    private function indexer(bool $working = false): Indexer
    {
        $indexer = $this->createStub(Indexer::class);
        $indexer->method('getId')->willReturn('catalog_product_price');
        $indexer->method('getTitle')->willReturn('Product Price');
        $indexer->method('isWorking')->willReturn($working);
        return $indexer;
    }

    public function testQueueStrategyPublishesCanonicalIdWithoutReindexing(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')
            ->with(ReindexDispatcher::TOPIC, 'catalog_product_price');
        $indexer = $this->createMock(Indexer::class);
        $indexer->method('getId')->willReturn('catalog_product_price');
        $indexer->expects($this->never())->method('reindexAll');

        $result = $this->dispatcher($indexer, 'queue', true, $publisher)->dispatch('alias');

        $this->assertSame(['mode' => 'queued', 'duration_ms' => 0], $result);
        $this->assertSame([], $this->lockCalls);
    }

    public function testQueueStrategyIsIgnoredWhileTheModuleIsDisabled(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $indexer = $this->createMock(Indexer::class);
        $indexer->method('getId')->willReturn('catalog_product_price');
        $indexer->expects($this->once())->method('reindexAll');

        $result = $this->dispatcher($indexer, 'queue', true, $publisher, false)->dispatch('catalog_product_price');

        $this->assertSame('sync', $result['mode']);
    }

    public function testStandardStrategyReindexesSynchronously(): void
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->method('getId')->willReturn('catalog_product_price');
        $indexer->method('isWorking')->willReturn(false);
        $indexer->expects($this->once())->method('reindexAll');

        $result = $this->dispatcher($indexer)->dispatch('catalog_product_price');

        $this->assertSame('sync', $result['mode']);
        $this->assertGreaterThanOrEqual(0, $result['duration_ms']);
        $this->assertSame([
            ['lock', 'panth_im_reindex_catalog_product_price', 0],
            ['unlock', 'panth_im_reindex_catalog_product_price'],
        ], $this->lockCalls);
    }

    public function testDispatchThrowsWhenAlreadyRunning(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Product Price is already being reindexed.');

        $this->dispatcher($this->indexer(), 'standard', false)->dispatch('catalog_product_price');
    }

    public function testReindexNowReturnsFalseWhenLockHeld(): void
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->never())->method('reindexAll');

        $this->assertFalse($this->dispatcher($indexer, 'standard', false)->reindexNow('x'));
        $this->assertSame([['lock', 'panth_im_reindex_x', 0]], $this->lockCalls);
    }

    public function testReindexNowSkipsWorkingIndexerAndReleasesLock(): void
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->method('isWorking')->willReturn(true);
        $indexer->expects($this->never())->method('reindexAll');

        $this->assertFalse($this->dispatcher($indexer)->reindexNow('x'));
        $this->assertSame(['unlock', 'panth_im_reindex_x'], end($this->lockCalls));
    }

    public function testLockIsReleasedWhenReindexThrows(): void
    {
        $indexer = $this->createStub(Indexer::class);
        $indexer->method('isWorking')->willReturn(false);
        $indexer->method('reindexAll')->willThrowException(new \RuntimeException('deadlock'));
        $dispatcher = $this->dispatcher($indexer);

        try {
            $dispatcher->reindexNow('x');
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('deadlock', $e->getMessage());
        }
        $this->assertSame(['unlock', 'panth_im_reindex_x'], end($this->lockCalls));
    }
}
