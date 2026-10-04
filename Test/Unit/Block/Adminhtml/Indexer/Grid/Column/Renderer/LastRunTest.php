<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Block\Adminhtml\Indexer\Grid\Column\Renderer;

use Magento\Backend\Block\Context;
use Magento\Backend\Block\Widget\Grid;
use Magento\Backend\Block\Widget\Grid\Column;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Panth\IndexerManager\Block\Adminhtml\Indexer\Grid\Column\Renderer\LastRun;
use Panth\IndexerManager\Model\RunLog;
use Panth\IndexerManager\Model\Tracker;
use PHPUnit\Framework\TestCase;

class LastRunTest extends TestCase
{
    private function renderer(Tracker $tracker, ?Column $column = null): LastRun
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn($v) => htmlspecialchars((string)$v));
        $escaper->method('escapeHtmlAttr')->willReturnCallback(static fn($v) => htmlspecialchars((string)$v));

        $renderer = new LastRun($this->createStub(Context::class), $escaper, $tracker, $this->dateFormatter());
        if ($column !== null) {
            $renderer->setColumn($column);
        }
        return $renderer;
    }

    private function column(array $rows): Column
    {
        $grid = $this->createStub(Grid::class);
        $grid->method('getCollection')->willReturn($rows);
        $column = $this->createStub(Column::class);
        $column->method('getGrid')->willReturn($grid);
        return $column;
    }

    public function testLatestRunsAreLoadedOnceForTheWholeGrid(): void
    {
        $rows = [
            new DataObject(['indexer_id' => 'catalog_product_price']),
            new DataObject(['indexer_id' => 'catalogsearch_fulltext']),
            new DataObject(['indexer_id' => 'customer_grid']),
        ];

        $tracker = $this->createMock(Tracker::class);
        $tracker->expects($this->once())->method('getLatestForAll')
            ->with(['catalog_product_price', 'catalogsearch_fulltext', 'customer_grid'])
            ->willReturn([
                'catalog_product_price' => new DataObject([
                    'status' => 'success',
                    'started_at' => '2026-09-30 10:00:00',
                    'duration_ms' => 120,
                ]),
            ]);
        $tracker->expects($this->never())->method('getLatest');

        $renderer = $this->renderer($tracker, $this->column($rows));

        $first = $renderer->render($rows[0]);
        $this->assertStringContainsString('2026-09-30 10:00:00', $first);
        $this->assertStringContainsString('(120 ms)', $first);
        $this->assertStringContainsString('panth-im__last-run--success', $first);
        $this->assertStringContainsString('data-panth-last-run="catalog_product_price"', $first);
        $this->assertStringContainsString('panth-im__muted', $renderer->render($rows[1]));
        $this->assertStringContainsString('panth-im__muted', $renderer->render($rows[2]));
    }

    public function testZeroDurationOmitsTimingAndValuesAreEscaped(): void
    {
        $row = new DataObject(['indexer_id' => 'a']);
        $tracker = $this->createStub(Tracker::class);
        $tracker->method('getLatestForAll')->willReturn([
            'a' => new DataObject(['status' => 'x"y', 'started_at' => '<now>', 'duration_ms' => 0]),
        ]);

        $html = $this->renderer($tracker, $this->column([$row]))->render($row);

        $this->assertStringNotContainsString('ms)', $html);
        $this->assertStringContainsString('&lt;now&gt;', $html);
        $this->assertStringContainsString('panth-im__last-run--x&quot;y', $html);
    }

    public function testRowWithoutIndexerIdRendersPlaceholderWithoutLookup(): void
    {
        $tracker = $this->createMock(Tracker::class);
        $tracker->expects($this->never())->method('getLatestForAll');
        $tracker->expects($this->never())->method('getLatest');

        $html = $this->renderer($tracker)->render(new DataObject([]));

        $this->assertSame('<span class="panth-im__muted">-</span>', $html);
    }

    public function testRowOutsideGridFallsBackToSingleLookup(): void
    {
        $tracker = $this->createMock(Tracker::class);
        $tracker->expects($this->once())->method('getLatestForAll')->with(['a'])->willReturn([]);
        $log = new class extends RunLog {
            public function __construct()
            {
            }
        };
        $log->setData(['status' => 'error', 'started_at' => 'T', 'duration_ms' => 5]);
        $tracker->expects($this->once())->method('getLatest')->with('late')->willReturn($log);

        $renderer = $this->renderer($tracker, $this->column([new DataObject(['indexer_id' => 'a'])]));
        $renderer->render(new DataObject(['indexer_id' => 'a']));
        $html = $renderer->render(new DataObject(['indexer_id' => 'late']));

        $this->assertStringContainsString('panth-im__last-run--error', $html);
        $this->assertStringContainsString('(5 ms)', $html);
    }

    public function testGridErrorsFallBackToCurrentRowOnly(): void
    {
        $column = $this->createStub(Column::class);
        $column->method('getGrid')->willThrowException(new \RuntimeException('no grid'));
        $tracker = $this->createMock(Tracker::class);
        $tracker->expects($this->once())->method('getLatestForAll')->with(['a'])->willReturn([]);

        $html = $this->renderer($tracker, $column)->render(new DataObject(['indexer_id' => 'a']));

        $this->assertStringContainsString('panth-im__muted', $html);
    }

    private function dateFormatter(): \Panth\IndexerManager\Model\DateFormatter
    {
        $formatter = $this->createStub(\Panth\IndexerManager\Model\DateFormatter::class);
        $formatter->method('format')->willReturnCallback(
            static fn($value) => (string)$value === '' ? '' : 'L(' . $value . ')'
        );
        return $formatter;
    }
}
