<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResourceConnection;
use Panth\IndexerManager\Controller\Adminhtml\Log;

class Clear extends Log
{
    public function __construct(Context $context, private readonly ResourceConnection $resource)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('panth_indexer_manager_run_log');
            $connection->delete($table);
            $this->messageManager->addSuccessMessage(__('Run log cleared.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->resultRedirectFactory->create()
            ->setPath('panth_indexer_manager/log/index');
    }
}
