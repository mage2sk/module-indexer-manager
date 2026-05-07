<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml;

use Magento\Backend\App\Action;

abstract class Log extends Action
{
    public const ADMIN_RESOURCE = 'Panth_IndexerManager::log';
}
