<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Indexer;

use Magento\Backend\Block\Template;

class Enhancer extends Template
{
    public function getStatusUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/manage/status');
    }

    public function getRunUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/manage/run');
    }

    public function getMassRunUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/manage/massRun');
    }

    public function getDetailsUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/manage/details');
    }

    public function getModeUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/manage/mode');
    }

    public function getLogUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/log/index');
    }
}
