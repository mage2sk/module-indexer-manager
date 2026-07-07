<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Controller\Adminhtml\Manage;

class Mode extends Manage
{
    public function __construct(
        Context $context,
        private readonly IndexerFactory $indexerFactory,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $indexerId = (string)$this->getRequest()->getParam('indexer_id');
        $mode = (string)$this->getRequest()->getParam('mode');
        if ($indexerId === '' || !in_array($mode, ['schedule', 'realtime'], true)) {
            return $result->setHttpResponseCode(400)
                ->setData(['success' => false, 'message' => __('Bad request.')->render()]);
        }
        try {
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerId);
            $indexer->setScheduled($mode === 'schedule');
            return $result->setData([
                'success' => true,
                'indexer_id' => $indexerId,
                'mode' => $mode,
                'message' => __('%1 set to %2.', $indexer->getTitle(), $mode === 'schedule' ? 'Update by Schedule' : 'Update on Save')->render(),
            ]);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
