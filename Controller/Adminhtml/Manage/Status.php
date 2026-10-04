<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\IndexerManager\Controller\Adminhtml\Manage;
use Panth\IndexerManager\Model\Indexer\StateProvider;

class Status extends Manage
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly StateProvider $stateProvider
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        try {
            $rows = $this->stateProvider->getAll();
            return $result->setData(['success' => true, 'indexers' => $rows]);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
