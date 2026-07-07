<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model\ResourceModel\RunLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\IndexerManager\Model\ResourceModel\RunLog as RunLogResource;
use Panth\IndexerManager\Model\RunLog;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'log_id';

    protected function _construct()
    {
        $this->_init(RunLog::class, RunLogResource::class);
    }
}
