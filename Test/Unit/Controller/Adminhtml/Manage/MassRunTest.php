<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Manage;

use Panth\IndexerManager\Controller\Adminhtml\Manage\MassRun;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class MassRunTest extends ControllerTestCase
{
    private function controller(array $outcomes): MassRun
    {
        $dispatcher = $this->createStub(ReindexDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function ($id) use ($outcomes) {
            $outcome = $outcomes[$id];
            if ($outcome instanceof \Throwable) {
                throw $outcome;
            }
            return $outcome;
        });
        return new MassRun($this->context(), $this->jsonFactory(), $dispatcher);
    }

    public function testNoSelectionIsABadRequest(): void
    {
        $this->controller([])->execute();

        $this->assertSame(400, $this->httpCode);
        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('No indexers selected.', $this->jsonData['message']);
    }

    public function testAllSyncSuccessesAreSummarised(): void
    {
        $this->params = ['indexer_ids' => ['a', 'b']];
        $this->controller([
            'a' => ['mode' => 'sync', 'duration_ms' => 10],
            'b' => ['mode' => 'sync', 'duration_ms' => 20],
        ])->execute();

        $this->assertTrue($this->jsonData['success']);
        $this->assertSame('2 succeeded, 0 failed.', $this->jsonData['summary']);
        $this->assertSame(
            ['indexer_id' => 'b', 'success' => true, 'mode' => 'sync', 'duration_ms' => 20],
            $this->jsonData['rows'][1]
        );
    }

    public function testPartialFailureMarksOverallFailureAndKeepsMessage(): void
    {
        $this->params = ['indexer_ids' => ['a', 'b']];
        $this->controller([
            'a' => ['mode' => 'sync', 'duration_ms' => 10],
            'b' => new \RuntimeException('boom'),
        ])->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('1 succeeded, 1 failed.', $this->jsonData['summary']);
        $this->assertSame(
            ['indexer_id' => 'b', 'success' => false, 'message' => 'boom'],
            $this->jsonData['rows'][1]
        );
    }

    public function testQueuedOutcomesUseQueuedSummary(): void
    {
        $this->params = ['indexer_ids' => ['a', 'b', 'c']];
        $this->controller([
            'a' => ['mode' => 'queued', 'duration_ms' => 0],
            'b' => ['mode' => 'queued', 'duration_ms' => 0],
            'c' => new \RuntimeException('x'),
        ])->execute();

        $this->assertSame('2 queued, 1 failed.', $this->jsonData['summary']);
        $this->assertCount(3, $this->jsonData['rows']);
    }

    public function testMixedQueuedAndImmediateRunsAreAllSummarised(): void
    {
        $this->params = ['indexer_ids' => ['a', 'b', 'c', 'd']];
        $this->controller([
            'a' => ['mode' => 'queued', 'duration_ms' => 0],
            'b' => ['mode' => 'sync', 'duration_ms' => 15],
            'c' => ['mode' => 'sync', 'duration_ms' => 25],
            'd' => new \RuntimeException('x'),
        ])->execute();

        $this->assertSame('2 succeeded, 1 queued, 1 failed.', $this->jsonData['summary']);
    }

    public function testScalarParamIsTreatedAsSingleId(): void
    {
        $this->params = ['indexer_ids' => 'a'];
        $this->controller(['a' => ['mode' => 'sync', 'duration_ms' => 1]])->execute();

        $this->assertCount(1, $this->jsonData['rows']);
        $this->assertSame('a', $this->jsonData['rows'][0]['indexer_id']);
    }
}
