<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Block\Adminhtml\Log;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\UrlInterface;
use Panth\IndexerManager\Block\Adminhtml\Log\Listing;
use Panth\IndexerManager\Model\DateFormatter;
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

    private function listing(
        array $params = [],
        array $entries = [],
        int $size = 0,
        int $last = 1,
        int &$creates = 0,
        ?array $indexerIds = []
    ): Listing {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn($key, $default = null) => $params[$key] ?? $default
        );
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route, $p = []) => $route . (!empty($p['_query']) ? '?' . http_build_query($p['_query']) : '')
        );
        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getUrlBuilder')->willReturn($url);

        $select = $this->createStub(Select::class);
        foreach (['distinct', 'from', 'order'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method) {
                $this->calls[] = array_merge(['select.' . $method], $args);
                return $select;
            });
        }
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        if ($indexerIds === null) {
            $connection->method('fetchCol')->willThrowException(new \RuntimeException('db down'));
        } else {
            $connection->method('fetchCol')->willReturn($indexerIds);
        }

        $collection = $this->createStub(Collection::class);
        foreach (['setOrder', 'setPageSize', 'setCurPage', 'addFieldToFilter'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method) {
                $this->calls[] = array_merge([$method], $args);
                return $collection;
            });
        }
        $collection->method('getIterator')->willReturn(new \ArrayIterator($entries));
        $collection->method('getSize')->willReturn($size);
        $collection->method('getLastPageNumber')->willReturn($last);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getMainTable')->willReturn('panth_indexer_manager_run_log');
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($collection, &$creates) {
            $creates++;
            return $collection;
        });

        $formatter = $this->createStub(DateFormatter::class);
        $formatter->method('format')->willReturnCallback(
            static fn($value) => (string)$value === '' ? '' : 'L(' . $value . ')'
        );

        return new Listing($context, $factory, $formatter);
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

    private function collectionCalls(): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn($call) => !str_starts_with((string)$call[0], 'select.')
        ));
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
            'rounds up to a minute' => [59995, '1m 0s'],
            'one minute' => [60000, '1m 0s'],
            'minutes' => [125400, '2m 5s'],
            'no sixty seconds' => [119600, '2m 0s'],
            'half second rounds up' => [89500, '1m 30s'],
            'an hour' => [3600000, '60m 0s'],
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

    public function testPageBeyondTheLastLoadsTheLastPage(): void
    {
        $this->listing(['p' => '999'], [], 25, 3)->getEntries();
        $this->assertContains(['setCurPage', 3], $this->collectionCalls());
    }

    public function testEffectivePageIsClampedToLastPage(): void
    {
        $this->assertSame(3, $this->listing(['p' => '99'], [], 30, 3)->getEffectivePage());
        $this->assertSame(2, $this->listing(['p' => '2'], [], 30, 3)->getEffectivePage());
    }

    public function testEntriesAreMappedWithLocalDatesAndCollectionIsBuiltOnce(): void
    {
        $creates = 0;
        $listing = $this->listing(['p' => '2'], [
            $this->entry([
                'log_id' => '3', 'indexer_id' => 'a', 'operation' => 'reindexAll', 'context' => 'cron',
                'status' => 'success', 'started_at' => 's', 'finished_at' => null, 'duration_ms' => '120',
                'admin_user' => null, 'message' => null,
            ]),
        ], 42, 5, $creates);

        $entries = $listing->getEntries();
        $this->assertSame(42, $listing->getTotalCount());
        $this->assertSame(5, $listing->getLastPage());

        $this->assertSame([
            'log_id' => 3, 'indexer_id' => 'a', 'operation' => 'reindexAll', 'context' => 'cron',
            'status' => 'success', 'started_at' => 'L(s)', 'finished_at' => '', 'duration_ms' => 120,
            'admin_user' => null, 'message' => null,
        ], $entries[0]);
        $this->assertSame(1, $creates);
        $this->assertSame(
            [
                ['setOrder', 'started_at', 'DESC'],
                ['setOrder', 'log_id', 'DESC'],
                ['setPageSize', Listing::PAGE_SIZE],
                ['setCurPage', 2],
            ],
            $this->collectionCalls()
        );
        $this->assertFalse($listing->hasActiveFilters());
    }

    public function testFiltersAreAppliedToTheCollection(): void
    {
        $listing = $this->listing([
            'q' => '  50%_off ',
            'indexer' => 'customer_grid',
            'status' => 'error',
            'context' => 'cron',
        ]);
        $listing->getEntries();

        $like = '%50\%\_off%';
        $this->assertSame(
            [
                ['addFieldToFilter', ['indexer_id', 'message', 'admin_user'],
                    [['like' => $like], ['like' => $like], ['like' => $like]]],
                ['addFieldToFilter', 'indexer_id', 'customer_grid'],
                ['addFieldToFilter', 'status', 'error'],
                ['addFieldToFilter', 'context', 'cron'],
                ['setOrder', 'started_at', 'DESC'],
                ['setOrder', 'log_id', 'DESC'],
                ['setPageSize', Listing::PAGE_SIZE],
                ['setCurPage', 1],
            ],
            $this->collectionCalls()
        );
        $this->assertTrue($listing->hasActiveFilters());
        $this->assertSame(
            ['q' => '50%_off', 'indexer' => 'customer_grid', 'status' => 'error', 'context' => 'cron'],
            $listing->getFilters()
        );
    }

    public function testInvalidFilterValuesAreIgnored(): void
    {
        $listing = $this->listing([
            'q' => '   ',
            'indexer' => "bad id'; DROP",
            'status' => 'exploded',
            'context' => 'frontend',
        ]);
        $listing->getEntries();

        $this->assertSame(['q' => '', 'indexer' => '', 'status' => '', 'context' => ''], $listing->getFilters());
        $this->assertFalse($listing->hasActiveFilters());
        $this->assertSame([], array_filter(
            $this->collectionCalls(),
            static fn($call) => $call[0] === 'addFieldToFilter'
        ));
    }

    public function testKeywordIsTruncatedTo100Characters(): void
    {
        $this->assertSame(100, mb_strlen($this->listing(['q' => str_repeat('a', 150)])->getFilters()['q']));
    }

    public static function sorts(): array
    {
        return [
            'default' => [[], 'started_at', 'DESC'],
            'field default direction asc' => [['sort' => 'indexer_id'], 'indexer_id', 'ASC'],
            'field default direction desc' => [['sort' => 'duration_ms'], 'duration_ms', 'DESC'],
            'explicit direction' => [['sort' => 'status', 'dir' => 'desc'], 'status', 'DESC'],
            'unknown field' => [['sort' => 'message; DROP', 'dir' => 'asc'], 'started_at', 'ASC'],
            'bad direction' => [['sort' => 'context', 'dir' => 'sideways'], 'context', 'ASC'],
        ];
    }

    #[DataProvider('sorts')]
    public function testSortFieldAndDirection(array $params, string $field, string $dir): void
    {
        $listing = $this->listing($params);
        $this->assertSame($field, $listing->getSortField());
        $this->assertSame($dir, $listing->getSortDirection());
        $listing->getEntries();
        $this->assertSame(['setOrder', $field, $dir], $this->collectionCalls()[0]);
    }

    public function testSortUrlsToggleTheActiveColumnAndKeepFilters(): void
    {
        $listing = $this->listing(['status' => 'error', 'sort' => 'indexer_id', 'dir' => 'asc', 'p' => '4']);

        $this->assertSame(
            'panth_indexer_manager/log/index?status=error&sort=indexer_id&dir=desc',
            $listing->getSortUrl('indexer_id')
        );
        $this->assertSame(
            'panth_indexer_manager/log/index?status=error&sort=duration_ms&dir=desc',
            $listing->getSortUrl('duration_ms')
        );
        $this->assertSame(
            'panth_indexer_manager/log/index?status=error&sort=started_at&dir=desc',
            $listing->getSortUrl('not_a_column')
        );
        $this->assertSame('ascending', $listing->getAriaSort('indexer_id'));
        $this->assertSame('none', $listing->getAriaSort('status'));
        $this->assertSame('descending', $this->listing()->getAriaSort('started_at'));
    }

    public function testIndexerOptionsComeFromTheLogTableAndKeepTheCurrentFilter(): void
    {
        $creates = 0;
        $listing = $this->listing(['indexer' => 'gone_indexer'], [], 0, 1, $creates, ['catalog_product_price', 'customer_grid']);

        $this->assertSame(['catalog_product_price', 'customer_grid', 'gone_indexer'], $listing->getIndexerOptions());
        $this->assertSame(['catalog_product_price', 'customer_grid', 'gone_indexer'], $listing->getIndexerOptions());
        $from = array_values(array_filter($this->calls, static fn($call) => $call[0] === 'select.from'));
        $this->assertSame(['select.from', 'panth_indexer_manager_run_log', ['indexer_id']], array_slice($from[0], 0, 3));
        $this->assertContains(['select.order', 'indexer_id ASC'], $this->calls);
    }

    public function testIndexerOptionsAreEmptyWhenTheQueryFails(): void
    {
        $creates = 0;
        $this->assertSame([], $this->listing([], [], 0, 1, $creates, null)->getIndexerOptions());
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
        $this->assertSame('panth_indexer_manager/log/index', $listing->getFilterUrl());
        $this->assertSame('panth_indexer_manager/log/clear', $listing->getClearUrl());
        $this->assertSame('indexer/indexer/list', $listing->getIndexManagementUrl());
    }

    public function testPageUrlsKeepFiltersAndExplicitSort(): void
    {
        $listing = $this->listing(['q' => 'grid', 'context' => 'admin', 'sort' => 'status']);
        $this->assertSame(
            'panth_indexer_manager/log/index?q=grid&context=admin&sort=status&dir=asc&p=2',
            $listing->getPageUrl(2)
        );
    }
}
