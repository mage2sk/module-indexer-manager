<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml\Indexer;

use Panth\IndexerManager\Controller\Adminhtml\Indexer\MassPanthReindex;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;
use Panth\IndexerManager\Test\Unit\Controller\Adminhtml\ControllerTestCase;

class MassPanthReindexTest extends ControllerTestCase
{
    private function controller(array $outcomes): MassPanthReindex
    {
        $dispatcher = $this->createStub(ReindexDispatcher::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function ($id) use ($outcomes) {
            if ($outcomes[$id] instanceof \Throwable) {
                throw $outcomes[$id];
            }
            return $outcomes[$id];
        });
        return new MassPanthReindex($this->context(), $dispatcher);
    }

    public function testEmptySelectionShowsErrorAndRedirectsToIndexList(): void
    {
        $this->controller([])->execute();

        $this->assertSame(['Please select at least one indexer.'], $this->messagesOf('error'));
        $this->assertSame('indexer/indexer/list', $this->redirectPath);
    }

    public function testMixedOutcomesProduceOneMessagePerCategory(): void
    {
        $this->params = ['indexer_ids' => ['a', 'b', 'c', 'd']];
        $this->controller([
            'a' => ['mode' => 'sync', 'duration_ms' => 1],
            'b' => ['mode' => 'sync', 'duration_ms' => 1],
            'c' => ['mode' => 'queued', 'duration_ms' => 0],
            'd' => new \RuntimeException('locked'),
        ])->execute();

        $this->assertSame(
            ['2 indexer(s) reindexed.', '1 indexer(s) queued for reindex.'],
            $this->messagesOf('success')
        );
        $this->assertSame(['d: locked'], $this->messagesOf('error'));
        $this->assertSame(['1 indexer(s) failed.'], $this->messagesOf('warning'));
        $this->assertSame('indexer/indexer/list', $this->redirectPath);
    }

    public function testAllSuccessAddsNoWarning(): void
    {
        $this->params = ['indexer_ids' => ['a']];
        $this->controller(['a' => ['mode' => 'sync', 'duration_ms' => 1]])->execute();

        $this->assertSame(['1 indexer(s) reindexed.'], $this->messagesOf('success'));
        $this->assertSame([], $this->messagesOf('warning'));
        $this->assertSame([], $this->messagesOf('error'));
    }
}
