<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Indexer\Grid\Column\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Panth\IndexerManager\Model\Tracker;

class LastRun extends AbstractRenderer
{
    private ?array $latestMap = null;

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
        $log = $id !== '' ? $this->getLatest($id) : null;
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

    private function getLatest(string $indexerId): ?DataObject
    {
        if ($this->latestMap === null) {
            $ids = $this->collectGridIndexerIds();
            $ids[] = $indexerId;
            $this->latestMap = array_fill_keys(array_unique($ids), null);
            foreach ($this->tracker->getLatestForAll(array_keys($this->latestMap)) as $key => $log) {
                $this->latestMap[(string)$key] = $log;
            }
        }
        if (!array_key_exists($indexerId, $this->latestMap)) {
            $this->latestMap[$indexerId] = $this->tracker->getLatest($indexerId);
        }
        return $this->latestMap[$indexerId];
    }

    private function collectGridIndexerIds(): array
    {
        $ids = [];
        try {
            $grid = $this->getColumn() ? $this->getColumn()->getGrid() : null;
            $collection = $grid ? $grid->getCollection() : null;
            if ($collection) {
                foreach ($collection as $item) {
                    $itemId = (string)$item->getIndexerId();
                    if ($itemId !== '') {
                        $ids[] = $itemId;
                    }
                }
            }
        } catch (\Throwable) {
            return [];
        }
        return $ids;
    }
}
