<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model;

use Magento\Framework\App\State as AppState;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\User\Model\User;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\Model\Notifier;
use Panth\IndexerManager\Model\ResourceModel\RunLog as RunLogResource;
use Panth\IndexerManager\Model\ResourceModel\RunLog\Collection;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;
use Panth\IndexerManager\Model\RunLog;
use Panth\IndexerManager\Model\RunLogFactory;
use Panth\IndexerManager\Model\Tracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TrackerTest extends TestCase
{
    /**
     * @var RunLog[]
     */
    private array $saved = [];

    /**
     * @var RunLog[]
     */
    private array $deleted = [];

    /**
     * @var string[]
     */
    private array $warnings = [];

    /**
     * @var RunLog[]
     */
    private array $notified = [];

    private function newLog(array $data = []): RunLog
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

    private function tracker(array $opts = []): Tracker
    {
        $config = $this->createStub(Config::class);
        $config->method('isTrackingEnabled')->willReturn($opts['tracking'] ?? true);
        $config->method('isFailuresOnly')->willReturn($opts['failuresOnly'] ?? false);

        $factory = $this->createStub(RunLogFactory::class);
        $factory->method('create')->willReturnCallback(fn() => $this->newLog());

        $resource = $this->createStub(RunLogResource::class);
        $resource->method('save')->willReturnCallback(function ($log) use ($resource, $opts) {
            if (isset($opts['saveError'])) {
                throw $opts['saveError'];
            }
            if (!$log->getId()) {
                $log->setId(55);
            }
            $this->saved[] = $log;
            return $resource;
        });
        $resource->method('delete')->willReturnCallback(function ($log) use ($resource) {
            $this->deleted[] = $log;
            return $resource;
        });

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-10-01 12:00:00');

        $appState = $this->createStub(AppState::class);
        if (isset($opts['areaError'])) {
            $appState->method('getAreaCode')->willThrowException($opts['areaError']);
        } else {
            $appState->method('getAreaCode')->willReturn($opts['area'] ?? 'adminhtml');
        }

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) {
            $this->warnings[] = $message;
        });

        $notifier = $this->createStub(Notifier::class);
        $notifier->method('notifyFailure')->willReturnCallback(function ($log) {
            $this->notified[] = $log;
        });

        return new Tracker(
            $factory,
            $resource,
            $opts['collectionFactory'] ?? $this->createStub(CollectionFactory::class),
            $dateTime,
            $config,
            $appState,
            $opts['session'] ?? new AuthSessionDouble(),
            $logger,
            $notifier
        );
    }

    public function testStartReturnsNullWhenTrackingDisabled(): void
    {
        $this->assertNull($this->tracker(['tracking' => false])->start('customer_grid'));
        $this->assertSame([], $this->saved);
    }

    public function testStartPersistsRunningLogWithAdminUser(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getUserName')->willReturn('jane');

        $log = $this->tracker(['session' => new AuthSessionDouble($user)])->start('customer_grid', 'reindexRow');

        $this->assertInstanceOf(RunLog::class, $log);
        $this->assertSame(55, $log->getId());
        $this->assertSame('customer_grid', $log->getData('indexer_id'));
        $this->assertSame('reindexRow', $log->getData('operation'));
        $this->assertSame('admin', $log->getData('context'));
        $this->assertSame(RunLog::STATUS_RUNNING, $log->getData('status'));
        $this->assertSame('2026-10-01 12:00:00', $log->getData('started_at'));
        $this->assertSame('jane', $log->getData('admin_user'));
    }

    public static function areas(): array
    {
        return [
            'admin' => ['adminhtml', 'admin'],
            'cron' => ['crontab', 'cron'],
            'rest' => ['webapi_rest', 'api'],
            'soap' => ['webapi_soap', 'api'],
            'graphql' => ['graphql', 'api'],
            'frontend falls to cli' => ['frontend', 'cli'],
            'global' => ['global', 'cli'],
        ];
    }

    #[DataProvider('areas')]
    public function testContextIsDerivedFromArea(string $area, string $context): void
    {
        $log = $this->tracker(['area' => $area])->start('x');
        $this->assertSame($context, $log->getData('context'));
        $this->assertSame('reindexAll', $log->getData('operation'));
    }

    public function testUnknownContextWhenAreaNotSet(): void
    {
        $log = $this->tracker(['areaError' => new \LogicException('Area code is not set')])->start('x');
        $this->assertSame('unknown', $log->getData('context'));
    }

    public function testAdminUserIsNullWithoutUserOrWhenSessionFails(): void
    {
        $this->assertNull($this->tracker()->start('x')->getData('admin_user'));
        $failing = new AuthSessionDouble(null, new \RuntimeException('no session'));
        $this->assertNull($this->tracker(['session' => $failing])->start('x')->getData('admin_user'));
    }

    public function testStartSwallowsSaveErrorsAndLogsWarning(): void
    {
        $tracker = $this->tracker(['saveError' => new \RuntimeException('table missing')]);

        $this->assertNull($tracker->start('x'));
        $this->assertSame(['[Panth IndexerManager] start log failed: table missing'], $this->warnings);
    }

    public function testFinishIgnoresMissingOrUnsavedLog(): void
    {
        $tracker = $this->tracker();
        $tracker->finish(null, 1.0);
        $tracker->finish($this->newLog(), 1.0, 'err');

        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->notified);
    }

    public function testFinishSuccessStoresDurationAndStatus(): void
    {
        $log = $this->newLog(['log_id' => 9]);
        $this->tracker()->finish($log, 1.2345);

        $this->assertSame([$log], $this->saved);
        $this->assertSame(RunLog::STATUS_SUCCESS, $log->getData('status'));
        $this->assertSame(1235, $log->getData('duration_ms'));
        $this->assertSame('2026-10-01 12:00:00', $log->getData('finished_at'));
        $this->assertNull($log->getData('message'));
        $this->assertSame([], $this->notified);
    }

    public function testFinishSuccessDeletesLogInFailuresOnlyMode(): void
    {
        $log = $this->newLog(['log_id' => 9]);
        $this->tracker(['failuresOnly' => true])->finish($log, 0.5);

        $this->assertSame([$log], $this->deleted);
        $this->assertSame([], $this->saved);
    }

    public function testFinishErrorStoresTruncatedMessageAndNotifies(): void
    {
        $log = $this->newLog(['log_id' => 9]);
        $this->tracker(['failuresOnly' => true])->finish($log, 0.0004, str_repeat('e', 5000));

        $this->assertSame([], $this->deleted);
        $this->assertSame([$log], $this->saved);
        $this->assertSame(RunLog::STATUS_ERROR, $log->getData('status'));
        $this->assertSame(4000, mb_strlen($log->getData('message')));
        $this->assertSame(0, $log->getData('duration_ms'));
        $this->assertSame([$log], $this->notified);
    }

    public function testFinishSwallowsSaveErrors(): void
    {
        $log = $this->newLog(['log_id' => 9]);
        $this->tracker(['saveError' => new \RuntimeException('gone')])->finish($log, 1.0, 'x');

        $this->assertSame(['[Panth IndexerManager] finish log failed: gone'], $this->warnings);
        $this->assertSame([], $this->notified);
    }

    private function simpleCollection(RunLog $first, array &$calls): CollectionFactory
    {
        $collection = $this->createStub(Collection::class);
        foreach (['addFieldToFilter', 'setOrder', 'setPageSize'] as $method) {
            $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method, &$calls) {
                $calls[] = array_merge([$method], $args);
                return $collection;
            });
        }
        $collection->method('getFirstItem')->willReturn($first);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        return $factory;
    }

    public function testGetLatestReturnsNewestRun(): void
    {
        $calls = [];
        $first = $this->newLog(['log_id' => 3]);
        $tracker = $this->tracker(['collectionFactory' => $this->simpleCollection($first, $calls)]);

        $this->assertSame($first, $tracker->getLatest('customer_grid'));
        $this->assertSame([
            ['addFieldToFilter', 'indexer_id', 'customer_grid'],
            ['setOrder', 'started_at', 'DESC'],
            ['setPageSize', 1],
        ], $calls);
    }

    public function testGetLatestReturnsNullForEmptyCollection(): void
    {
        $calls = [];
        $tracker = $this->tracker(['collectionFactory' => $this->simpleCollection($this->newLog(), $calls)]);

        $this->assertNull($tracker->getLatest('customer_grid'));
    }

    public function testGetLatestForAllWithNoIdsSkipsQuery(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $this->assertSame([], $this->tracker(['collectionFactory' => $factory])->getLatestForAll([]));
    }

    public function testGetLatestForAllKeepsHighestIdPerIndexerAndDedupesInput(): void
    {
        $whereArgs = [];
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value) use ($select, &$whereArgs) {
            $whereArgs[] = [$cond, $value];
            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);

        $items = [
            $this->newLog(['log_id' => 1, 'indexer_id' => 'a']),
            $this->newLog(['log_id' => 4, 'indexer_id' => 'a']),
            $this->newLog(['log_id' => 2, 'indexer_id' => 'a']),
            $this->newLog(['log_id' => 3, 'indexer_id' => 'b']),
        ];
        $collection = $this->createStub(Collection::class);
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getMainTable')->willReturn('panth_indexer_manager_run_log');
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($items));
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $result = $this->tracker(['collectionFactory' => $factory])->getLatestForAll(['a', 'b', 'a']);

        $this->assertSame(['a', 'b'], array_keys($result));
        $this->assertSame(4, $result['a']->getId());
        $this->assertSame(3, $result['b']->getId());
        $this->assertSame([['indexer_id IN (?)', ['a', 'b']]], $whereArgs);
    }
}
