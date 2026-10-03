<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Block\Adminhtml\Indexer\Grid\Column\Renderer;

use Magento\Backend\Block\Context;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Panth\IndexerManager\Block\Adminhtml\Indexer\Grid\Column\Renderer\Actions;
use PHPUnit\Framework\TestCase;

class ActionsTest extends TestCase
{
    private function renderer(): Actions
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static fn($v) => htmlspecialchars((string)$v));
        $escaper->method('escapeHtmlAttr')->willReturnCallback(static fn($v) => htmlspecialchars((string)$v));
        return new Actions($this->createStub(Context::class), $escaper);
    }

    public function testEmptyIdRendersNothing(): void
    {
        $this->assertSame('', $this->renderer()->render(new DataObject([])));
    }

    public function testRendersRunAndViewButtonsForIndexer(): void
    {
        $html = $this->renderer()->render(new DataObject(['indexer_id' => 'customer_grid']));

        $this->assertStringContainsString('data-panth-action="run" data-panth-id="customer_grid"', $html);
        $this->assertStringContainsString('data-panth-action="view" data-panth-id="customer_grid"', $html);
        $this->assertStringContainsString('<span>Reindex</span>', $html);
        $this->assertStringContainsString('<span>View</span>', $html);
        $this->assertSame(3, substr_count($html, 'data-panth-id="customer_grid"'));
    }

    public function testIdIsEscapedInAttributes(): void
    {
        $html = $this->renderer()->render(new DataObject(['indexer_id' => 'a"b']));

        $this->assertStringContainsString('data-panth-id="a&quot;b"', $html);
        $this->assertStringNotContainsString('data-panth-id="a"b"', $html);
    }
}
