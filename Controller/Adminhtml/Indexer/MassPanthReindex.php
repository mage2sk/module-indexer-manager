<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 *
 * Handles the "Reindex now (Panth)" mass-action submitted from
 * Magento's native Index Management grid.
 */
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Indexer;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Indexer\Model\IndexerFactory;

class MassPanthReindex extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Indexer::changeMode';

    public function __construct(
        Context $context,
        private readonly IndexerFactory $indexerFactory
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
        $fail = 0;
        foreach ($ids as $id) {
            try {
                $indexer = $this->indexerFactory->create();
                $indexer->load((string)$id);
                $indexer->reindexAll();
                $ok++;
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
        if ($fail > 0) {
            $this->messageManager->addWarningMessage(__('%1 indexer(s) failed.', $fail));
        }

        return $this->resultRedirectFactory->create()->setPath('indexer/indexer/list');
    }
}
