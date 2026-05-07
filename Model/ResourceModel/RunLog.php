<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RunLog extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('panth_indexer_manager_run_log', 'log_id');
    }
}
