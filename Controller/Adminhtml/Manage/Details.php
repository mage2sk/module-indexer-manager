<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\IndexerManager\Controller\Adminhtml\Manage;
use Panth\IndexerManager\Model\Indexer\StateProvider;
use Panth\IndexerManager\Model\ResourceModel\RunLog\CollectionFactory;

class Details extends Manage
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly StateProvider $stateProvider,
        private readonly CollectionFactory $logCollectionFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $indexerId = (string)$this->getRequest()->getParam('indexer_id');
        if ($indexerId === '') {
            return $result->setHttpResponseCode(400)
                ->setData(['success' => false, 'message' => __('Missing indexer_id.')->render()]);
        }
        try {
            $state = $this->stateProvider->getOne($indexerId);
            $logs = $this->logCollectionFactory->create()
                ->addFieldToFilter('indexer_id', $indexerId)
                ->setOrder('started_at', 'DESC')
                ->setPageSize(10);

            $runs = [];
            foreach ($logs as $log) {
                $runs[] = [
                    'log_id' => (int)$log->getId(),
                    'started_at' => $log->getData('started_at'),
                    'finished_at' => $log->getData('finished_at'),
                    'status' => $log->getData('status'),
                    'duration_ms' => (int)$log->getData('duration_ms'),
                    'context' => $log->getData('context'),
                    'admin_user' => $log->getData('admin_user'),
                    'message' => $log->getData('message'),
                ];
            }

            return $result->setData([
                'success' => true,
                'indexer' => $state,
                'recent_runs' => $runs,
            ]);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
