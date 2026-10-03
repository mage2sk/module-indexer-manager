<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Block\Adminhtml\Log;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Panth\IndexerManager\Block\Adminhtml\Log\Listing;
use Panth\IndexerManager\Model\ResourceModel\RunLog\Collection;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;
use Panth\IndexerManager\Model\RunLog;
use PHPUnit\Framework\Attributes\DataProvider;
use Panth\IndexerManager\Test\Unit\Block\ObjectManagerStubTrait;
use PHPUnit\Framework\TestCase;

class ListingTest extends TestCase
{
    use ObjectManagerStubTrait;

    protected function setUp(): void
    {
        $this->installObjectManagerStub();
    }

    protected function tearDown(): void
    {
        $this->restoreObjectManager();
    }

    /**
     * @var array
     */
    private array $calls = [];

    private function listing(array $params = [], array $entries = [], int $size = 0, int $last = 1, int &$creates = 0)
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $p = []) => $route . ($p ? '?' . http_build_query($p) : '')
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);

        $collection = $this->createStub(Collection::class);
        foreach (['setOrder', 'setPageSize', 'setCurPage'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                $this->calls[] = array_merge([$method], $args);
                return $collection;
            });
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator($entries));
        $collection->method('getSize')->willReturn($size);
        $collection->method('getLastPageNumber')->willReturn($last);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($collection, &$creates) {
            $creates++;
            return $collection;
        });

        return new Listing($context, $factory);
    }

    private function entry(array $data): RunLog
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

    public static function durations(): array
    {
        return [
            'zero' => [0, '-'],
            'negative' => [-5, '-'],
            'milliseconds' => [999, '999 ms'],
            'one second' => [1000, '1.00 s'],
            'seconds' => [12345, '12.35 s'],
            'just under a minute' => [59990, '59.99 s'],
            'one minute' => [60000, '1m 0s'],
            'minutes' => [125400, '2m 5s'],
        ];
    }

    #[DataProvider('durations')]
    public function testFormatDuration(int $ms, string $expected): void
    {
        $this->assertSame($expected, $this->listing()->formatDuration($ms));
    }

    public static function ranges(): array
    {
        return [
            'start' => [1, 10, 5, [1, 2, 3, 4, 5]],
            'middle' => [6, 10, 5, [4, 5, 6, 7, 8]],
            'end' => [10, 10, 5, [6, 7, 8, 9, 10]],
            'near end' => [9, 10, 5, [6, 7, 8, 9, 10]],
            'few pages' => [2, 3, 5, [1, 2, 3]],
            'single page' => [1, 1, 5, [1]],
            'even window' => [5, 10, 4, [3, 4, 5, 6]],
        ];
    }

    #[DataProvider('ranges')]
    public function testPageRange(int $current, int $last, int $window, array $expected): void
    {
        $this->assertSame($expected, $this->listing()->getPageRange($current, $last, $window));
    }

    public static function badges(): array
    {
        return [
            'success' => [RunLog::STATUS_SUCCESS, 'grid-severity-notice', 'Success'],
            'error' => [RunLog::STATUS_ERROR, 'grid-severity-critical', 'Error'],
            'running' => [RunLog::STATUS_RUNNING, 'grid-severity-minor', 'Running'],
            'unknown' => ['queued', '', 'Queued'],
        ];
    }

    #[DataProvider('badges')]
    public function testStatusBadge(string $status, string $class, string $label): void
    {
        $this->assertSame(['class' => $class, 'label' => $label], $this->listing()->getStatusBadge($status));
    }

    public static function pages(): array
    {
        return [
            'missing' => [[], 1],
            'valid' => [['p' => '3'], 3],
            'zero' => [['p' => '0'], 1],
            'negative' => [['p' => '-4'], 1],
            'garbage' => [['p' => 'abc'], 1],
        ];
    }

    #[DataProvider('pages')]
    public function testCurrentPageIsAtLeastOne(array $params, int $expected): void
    {
        $this->assertSame($expected, $this->listing($params)->getCurrentPage());
    }

    public function testEntriesAreMappedAndCollectionIsBuiltOnce(): void
    {
        $creates = 0;
        $listing = $this->listing(['p' => '2'], [
            $this->entry([
                'log_id' => '3', 'indexer_id' => 'a', 'operation' => 'reindexAll', 'context' => 'cron',
                'status' => 'success', 'started_at' => 's', 'finished_at' => 'f', 'duration_ms' => '120',
                'admin_user' => null, 'message' => null,
            ]),
        ], 42, 5, $creates);

        $entries = $listing->getEntries();
        $this->assertSame(42, $listing->getTotalCount());
        $this->assertSame(5, $listing->getLastPage());

        $this->assertSame([
            'log_id' => 3, 'indexer_id' => 'a', 'operation' => 'reindexAll', 'context' => 'cron',
            'status' => 'success', 'started_at' => 's', 'finished_at' => 'f', 'duration_ms' => 120,
            'admin_user' => null, 'message' => null,
        ], $entries[0]);
        $this->assertSame(1, $creates);
        $this->assertSame(
            [['setOrder', 'started_at', 'DESC'], ['setPageSize', Listing::PAGE_SIZE], ['setCurPage', 2]],
            $this->calls
        );
    }

    public function testLastPageIsAtLeastOne(): void
    {
        $this->assertSame(1, $this->listing([], [], 0, 0)->getLastPage());
    }

    public function testUrls(): void
    {
        $listing = $this->listing();
        $this->assertSame('panth_indexer_manager/log/index?p=4', $listing->getPageUrl(4));
        $this->assertSame('panth_indexer_manager/log/index?p=1', $listing->getPageUrl(-2));
        $this->assertSame('panth_indexer_manager/log/clear', $listing->getClearUrl());
        $this->assertSame('indexer/indexer/list', $listing->getIndexManagementUrl());
    }
}
