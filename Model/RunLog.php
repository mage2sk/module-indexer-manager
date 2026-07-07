<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model;

use Magento\Framework\Model\AbstractModel;
use Panth\IndexerManager\Model\ResourceModel\RunLog as RunLogResource;

class RunLog extends AbstractModel
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';

    protected $_eventPrefix = 'panth_indexer_manager_run_log';

    protected function _construct()
    {
        $this->_init(RunLogResource::class);
    }
}
