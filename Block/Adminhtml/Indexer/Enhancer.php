<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Block\Adminhtml\Indexer;

use Magento\Backend\Block\Template;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\ViewModel\PollSettings;

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

    public function getPollInterval(): int
    {
        $settings = $this->getData('poll_settings');
        return $settings instanceof PollSettings ? $settings->getPollInterval() : Config::POLL_INTERVAL_DEFAULT;
    }

    public function getLogUrl(): string
    {
        return $this->getUrl('panth_indexer_manager/log/index');
    }
}
