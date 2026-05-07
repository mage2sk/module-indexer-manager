<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\IndexerManager\Controller\Adminhtml\Log;

class Index extends Log
{
    public function __construct(Context $context, private readonly PageFactory $resultPageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Panth_IndexerManager::log');
        $page->getConfig()->getTitle()->prepend(__('Indexer Run Log'));
        return $page;
    }
}
