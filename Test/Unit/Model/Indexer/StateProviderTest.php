<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model\Indexer;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\StateInterface as ViewStateInterface;
use Magento\Framework\Mview\ViewInterface;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\Indexer\Collection as IndexerCollection;
use Magento\Indexer\Model\Indexer\CollectionFactory as IndexerCollectionFactory;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Model\Indexer\StateProvider;
use Panth\IndexerManager\Model\RunLog;
use Panth\IndexerManager\Model\Tracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StateProviderTest extends TestCase
{
    /**
     * @var array
     */
    private array $backlogQuery = [];

    private function indexer(array $o): IndexerInterface
    {
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('getId')->willReturn($o['id'] ?? 'idx');
        $indexer->method('getTitle')->willReturn($o['title'] ?? 'Title');
        $indexer->method('getDescription')->willReturn('Desc');
        $indexer->method('getStatus')->willReturn($o['status'] ?? StateInterface::STATUS_VALID);
        $indexer->method('isScheduled')->willReturn($o['scheduled'] ?? false);
        $indexer->method('getLatestUpdated')->willReturn('2026-10-01 00:00:00');
        if (isset($o['view'])) {
            $indexer->method('getView')->willReturn($o['view']);
        } elseif (isset($o['viewError'])) {
            $indexer->method('getView')->willThrowException($o['viewError']);
        }
        return $indexer;
    }

    private function view(string $id, string $stateStatus, int $versionId): ViewInterface
    {
        $state = $this->createStub(ViewStateInterface::class);
        $state->method('loadByView')->willReturnSelf();
        $state->method('getStatus')->willReturn($stateStatus);
        $state->method('getVersionId')->willReturn($versionId);
        $changelog = $this->createStub(ChangelogInterface::class);
        $changelog->method('setViewId')->willReturnSelf();
        $changelog->method('getName')->willReturn('catalog_product_price_cl');
        $changelog->method('getColumnName')->willReturn('entity_id');
        $view = $this->createStub(ViewInterface::class);
        $view->method('getId')->willReturn($id);
        $view->method('getState')->willReturn($state);
        $view->method('getChangelog')->willReturn($changelog);
        return $view;
    }

    private function provider(array $indexers, int $backlog = 0, array $latest = [], ?IndexerFactory $f = null)
    {
        $collection = $this->createStub(IndexerCollection::class);
        $collection->method('getItems')->willReturn($indexers);
        $collectionFactory = $this->createStub(IndexerCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $tracker = $this->createStub(Tracker::class);
        $tracker->method('getLatestForAll')->willReturn($latest);
        $tracker->method('getLatest')->willReturnCallback(static fn($id) => $latest[$id] ?? null);

        $select = $this->createStub(Select::class);
        $select->method('distinct')->willReturnSelf();
        $select->method('from')->willReturnCallback(function ($table, $cols) use ($select) {
            $this->backlogQuery[] = ['from', $table, $cols];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value) use ($select) {
            $this->backlogQuery[] = ['where', $cond, $value];
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($limit) use ($select) {
            $this->backlogQuery[] = ['limit', $limit];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn((string)$backlog);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn($t) => 'pfx_' . $t);

        return new StateProvider(
            $collectionFactory,
            $f ?? $this->createStub(IndexerFactory::class),
            $tracker,
            $resource
        );
    }

    private function runLog(array $data): RunLog
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

    public function testGetAllBuildsRealtimeRowsWithLatestRun(): void
    {
        $latest = ['a' => $this->runLog([
            'log_id' => '12', 'started_at' => 's', 'finished_at' => 'f', 'duration_ms' => '99',
            'status' => 'success', 'context' => 'admin', 'admin_user' => 'jane', 'message' => null,
        ])];
        $rows = $this->provider([$this->indexer(['id' => 'a']), $this->indexer(['id' => 'b'])], 0, $latest)
            ->getAll();

        $this->assertCount(2, $rows);
        $this->assertSame('a', $rows[0]['id']);
        $this->assertSame('realtime', $rows[0]['mode']);
        $this->assertSame('Update on Save', $rows[0]['mode_label']);
        $this->assertFalse($rows[0]['schedule']['available']);
        $this->assertSame([
            'log_id' => 12, 'started_at' => 's', 'finished_at' => 'f', 'duration_ms' => 99,
            'status' => 'success', 'context' => 'admin', 'admin_user' => 'jane', 'message' => null,
        ], $rows[0]['latest_run']);
        $this->assertNull($rows[1]['latest_run']);
    }

    public static function statuses(): array
    {
        return [
            'valid' => [StateInterface::STATUS_VALID, 'Ready', 'grid-severity-notice', false],
            'invalid' => [StateInterface::STATUS_INVALID, 'Reindex required', 'grid-severity-critical', false],
            'working' => [StateInterface::STATUS_WORKING, 'Processing', 'grid-severity-minor', true],
            'suspended' => [StateInterface::STATUS_SUSPENDED, 'Suspended', 'grid-severity-minor', false],
            'other' => ['custom', 'Custom', '', false],
        ];
    }

    #[DataProvider('statuses')]
    public function testStatusLabelAndClass(string $status, string $label, string $class, bool $working): void
    {
        $row = $this->provider([$this->indexer(['status' => $status])])->getAll()[0];

        $this->assertSame($status, $row['status']);
        $this->assertSame($label, $row['status_label']);
        $this->assertSame($class, $row['status_class']);
        $this->assertSame($working, $row['is_working']);
    }

    public static function backlogs(): array
    {
        return [
            'empty idle' => [0, 'idle', 'grid-severity-notice', 'IDLE (0 in backlog)', false, 0],
            'small idle' => [10, 'idle', 'grid-severity-notice', 'IDLE (10 in backlog)', false, 10],
            'minor idle' => [11, 'idle', 'grid-severity-minor', 'IDLE (11 in backlog)', false, 11],
            'major idle' => [101, 'idle', 'grid-severity-major', 'IDLE (101 in backlog)', false, 101],
            'critical idle' => [1001, 'idle', 'grid-severity-critical', 'IDLE (1001 in backlog)', false, 1001],
            'exact cap' => [10000, 'idle', 'grid-severity-critical', 'IDLE (10000 in backlog)', false, 10000],
            'over cap' => [10001, 'idle', 'grid-severity-critical', 'IDLE (10000+ in backlog)', true, 10000],
            'working state' => [5, 'working', 'grid-severity-minor', 'WORKING (5 in backlog)', false, 5],
        ];
    }

    #[DataProvider('backlogs')]
    public function testScheduledBacklog(
        int $count,
        string $state,
        string $class,
        string $label,
        bool $capped,
        int $shown
    ): void {
        $indexer = $this->indexer(['scheduled' => true, 'view' => $this->view('price', $state, 77)]);
        $row = $this->provider([$indexer], $count)->getAll()[0];

        $this->assertSame('schedule', $row['mode']);
        $this->assertSame('Update by Schedule', $row['mode_label']);
        $this->assertTrue($row['schedule']['available']);
        $this->assertSame($state, $row['schedule']['status']);
        $this->assertSame($shown, $row['schedule']['backlog']);
        $this->assertSame($capped, $row['schedule']['backlog_capped']);
        $this->assertSame($class, $row['schedule']['class']);
        $this->assertSame($label, $row['schedule']['label']);
    }

    public function testBacklogQueryCountsChangesAfterStateVersion(): void
    {
        $indexer = $this->indexer(['scheduled' => true, 'view' => $this->view('price', 'idle', 77)]);
        $this->provider([$indexer], 3)->getAll();

        $this->assertContains(['from', 'pfx_catalog_product_price_cl', ['entity_id']], $this->backlogQuery);
        $this->assertContains(['where', 'version_id > ?', 77], $this->backlogQuery);
        $this->assertContains(['limit', StateProvider::BACKLOG_CAP + 1], $this->backlogQuery);
    }

    public function testScheduledWithoutViewIdIsUnavailable(): void
    {
        $indexer = $this->indexer(['scheduled' => true, 'view' => $this->view('', 'idle', 0)]);
        $schedule = $this->provider([$indexer])->getAll()[0]['schedule'];

        $this->assertFalse($schedule['available']);
        $this->assertSame('', $schedule['label']);
        $this->assertSame('grid-severity-notice', $schedule['class']);
    }

    public function testViewErrorIsReportedAsUnavailable(): void
    {
        $indexer = $this->indexer(['scheduled' => true, 'viewError' => new \RuntimeException('broken')]);
        $schedule = $this->provider([$indexer])->getAll()[0]['schedule'];

        $this->assertFalse($schedule['available']);
        $this->assertSame('UNAVAILABLE', $schedule['label']);
        $this->assertSame('grid-severity-minor', $schedule['class']);
    }

    public function testGetOneLoadsSingleIndexer(): void
    {
        $indexer = $this->createMock(Indexer::class);
        $indexer->expects($this->once())->method('load')->with('customer_grid');
        $indexer->method('getId')->willReturn('customer_grid');
        $indexer->method('getStatus')->willReturn(StateInterface::STATUS_INVALID);
        $indexer->method('isScheduled')->willReturn(false);
        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('create')->willReturn($indexer);

        $row = $this->provider([], 0, ['customer_grid' => $this->runLog(['log_id' => 5])], $factory)
            ->getOne('customer_grid');

        $this->assertSame('customer_grid', $row['id']);
        $this->assertSame('Reindex required', $row['status_label']);
        $this->assertSame(5, $row['latest_run']['log_id']);
    }
}
