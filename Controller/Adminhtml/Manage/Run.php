<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Indexer\Model\IndexerFactory;
use Panth\IndexerManager\Controller\Adminhtml\Manage;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;

class Run extends Manage
{
    public function __construct(
        Context $context,
        private readonly IndexerFactory $indexerFactory,
        private readonly JsonFactory $jsonFactory,
        private readonly ReindexDispatcher $dispatcher
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
            $indexer = $this->indexerFactory->create();
            $indexer->load($indexerId);

            $outcome = $this->dispatcher->dispatch($indexerId);

            $message = $outcome['mode'] === 'queued'
                ? __('%1 queued for reindex.', $indexer->getTitle())->render()
                : __('%1 reindexed in %2 ms.', $indexer->getTitle(), $outcome['duration_ms'])->render();

            return $result->setData([
                'success' => true,
                'indexer_id' => $indexerId,
                'mode' => $outcome['mode'],
                'duration_ms' => $outcome['duration_ms'],
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            return $result->setHttpResponseCode(500)
                ->setData(['success' => false, 'indexer_id' => $indexerId, 'message' => $e->getMessage()]);
        }
    }
}
