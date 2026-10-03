<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Plugin;

use Magento\Framework\Indexer\IndexerInterface;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\Model\RunLog;
use Panth\IndexerManager\Model\Tracker;
use Panth\IndexerManager\Plugin\IndexerTrackingPlugin;
use PHPUnit\Framework\TestCase;

class IndexerTrackingPluginTest extends TestCase
{
    /**
     * @var array
     */
    private array $finished = [];

    /**
     * @var array
     */
    private array $started = [];

    private function plugin(bool $tracking = true, ?RunLog $log = null): IndexerTrackingPlugin
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn($tracking);
        $tracker = $this->createStub(Tracker::class);
        $tracker->method('start')->willReturnCallback(function ($id, $op) use ($log) {
            $this->started[] = [$id, $op];
            return $log;
        });
        $tracker->method('finish')->willReturnCallback(function ($l, $duration, $error = null) {
            $this->finished[] = [$l, $duration, $error];
        });
        return new IndexerTrackingPlugin($tracker, $config);
    }

    private function subject(string $id): IndexerInterface
    {
        $subject = $this->createStub(IndexerInterface::class);
        $subject->method('getId')->willReturn($id);
        return $subject;
    }

    public function testDisabledTrackingJustProceeds(): void
    {
        $result = $this->plugin(false)->aroundReindexAll($this->subject('a'), static fn() => 'done');

        $this->assertSame('done', $result);
        $this->assertSame([], $this->started);
        $this->assertSame([], $this->finished);
    }

    public function testEmptyIndexerIdIsNotTracked(): void
    {
        $result = $this->plugin()->aroundReindexAll($this->subject(''), static fn() => 'done');

        $this->assertSame('done', $result);
        $this->assertSame([], $this->started);
    }

    public function testSuccessfulReindexAllIsStartedAndFinished(): void
    {
        $log = new class extends RunLog {
            public function __construct()
            {
            }
        };
        $result = $this->plugin(true, $log)->aroundReindexAll($this->subject('a'), static fn() => 'r');

        $this->assertSame('r', $result);
        $this->assertSame([['a', 'reindexAll']], $this->started);
        $this->assertCount(1, $this->finished);
        $this->assertSame($log, $this->finished[0][0]);
        $this->assertIsFloat($this->finished[0][1]);
        $this->assertGreaterThanOrEqual(0.0, $this->finished[0][1]);
        $this->assertNull($this->finished[0][2]);
    }

    public function testFailureIsRecordedAndRethrown(): void
    {
        $error = new \RuntimeException('index broken');
        try {
            $this->plugin()->aroundReindexAll($this->subject('a'), static function () use ($error) {
                throw $error;
            });
            $this->fail('Exception expected');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }
        $this->assertSame('index broken', $this->finished[0][2]);
    }

    public function testReindexRowPassesIdAndOperation(): void
    {
        $received = null;
        $result = $this->plugin()->aroundReindexRow($this->subject('a'), static function ($id) use (&$received) {
            $received = $id;
            return 'row';
        }, 42);

        $this->assertSame('row', $result);
        $this->assertSame(42, $received);
        $this->assertSame([['a', 'reindexRow']], $this->started);
    }

    public function testReindexListPassesIdsAndOperation(): void
    {
        $received = null;
        $this->plugin()->aroundReindexList($this->subject('a'), static function ($ids) use (&$received) {
            $received = $ids;
        }, [1, 2]);

        $this->assertSame([1, 2], $received);
        $this->assertSame([['a', 'reindexList']], $this->started);
    }

    public function testDisabledTrackingStillPassesArguments(): void
    {
        $result = $this->plugin(false)->aroundReindexList(
            $this->subject('a'),
            static fn($ids) => array_sum($ids),
            [1, 2, 3]
        );
        $this->assertSame(6, $result);
    }
}
