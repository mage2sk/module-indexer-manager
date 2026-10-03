<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Indexer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;

class MassPanthReindex extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Panth_IndexerManager::manage';

    public function __construct(
        Context $context,
        private readonly ReindexDispatcher $dispatcher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $ids = (array)$this->getRequest()->getParam('indexer_ids', []);
        if (!$ids) {
            $this->messageManager->addErrorMessage(__('Please select at least one indexer.'));
            return $this->resultRedirectFactory->create()->setPath('indexer/indexer/list');
        }

        $ok = 0;
        $queued = 0;
        $fail = 0;
        foreach ($ids as $id) {
            $id = (string)$id;
            try {
                $outcome = $this->dispatcher->dispatch($id);
                if ($outcome['mode'] === 'queued') {
                    $queued++;
                } else {
                    $ok++;
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->messageManager->addErrorMessage(
                    __('%1: %2', $id, $e->getMessage())
                );
            }
        }

        if ($ok > 0) {
            $this->messageManager->addSuccessMessage(__('%1 indexer(s) reindexed.', $ok));
        }
        if ($queued > 0) {
            $this->messageManager->addSuccessMessage(__('%1 indexer(s) queued for reindex.', $queued));
        }
        if ($fail > 0) {
            $this->messageManager->addWarningMessage(__('%1 indexer(s) failed.', $fail));
        }

        return $this->resultRedirectFactory->create()->setPath('indexer/indexer/list');
    }
}
