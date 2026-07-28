<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Indexer\Grid\Column\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Panth\IndexerManager\Model\Tracker;

class LastRun extends AbstractRenderer
{
    public function __construct(
        \Magento\Backend\Block\Context $context,
        private readonly Escaper $escaper,
        private readonly Tracker $tracker,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(DataObject $row)
    {
        $id = (string)$row->getIndexerId();
        $log = $id !== '' ? $this->tracker->getLatest($id) : null;
        if (!$log) {
            return '<span class="panth-im__muted">-</span>';
        }
        $status = (string)$log->getData('status');
        $started = (string)$log->getData('started_at');
        $duration = (int)$log->getData('duration_ms');

        return '<span class="panth-im__last-run panth-im__last-run--' . $this->escaper->escapeHtmlAttr($status) . '" '
            . 'data-panth-last-run="' . $this->escaper->escapeHtmlAttr($id) . '">'
            . $this->escaper->escapeHtml($started)
            . ($duration > 0 ? ' <small>(' . $duration . ' ms)</small>' : '')
            . '</span>';
    }
}
