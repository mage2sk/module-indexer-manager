<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Indexer\Grid\Column\Renderer;

use Magento\Backend\Block\Widget\Grid\Column\Renderer\AbstractRenderer;
use Magento\Framework\DataObject;
use Magento\Framework\Escaper;

class Actions extends AbstractRenderer
{
    public function __construct(
        \Magento\Backend\Block\Context $context,
        private readonly Escaper $escaper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function render(DataObject $row)
    {
        $id = (string)$row->getIndexerId();
        if ($id === '') {
            return '';
        }
        $idAttr = $this->escaper->escapeHtmlAttr($id);

        return '<div class="panth-im__row-actions" data-panth-id="' . $idAttr . '">'
            . '<button type="button" class="action-default scalable panth-im__btn panth-im__btn--run" '
            . 'data-panth-action="run" data-panth-id="' . $idAttr . '">'
            . '<span>' . $this->escaper->escapeHtml((string)__('Reindex')) . '</span>'
            . '</button>'
            . '<button type="button" class="action-default scalable panth-im__btn panth-im__btn--view" '
            . 'data-panth-action="view" data-panth-id="' . $idAttr . '">'
            . '<span>' . $this->escaper->escapeHtml((string)__('View')) . '</span>'
            . '</button>'
            . '</div>';
    }
}
