<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\IndexerManager\Controller\Adminhtml\Manage;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;

class MassRun extends Manage
{
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ReindexDispatcher $dispatcher
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $ids = (array)$this->getRequest()->getParam('indexer_ids', []);
        if (!$ids) {
            return $result->setHttpResponseCode(400)
                ->setData(['success' => false, 'message' => __('No indexers selected.')->render()]);
        }

        $report = [];
        $queued = 0;
        foreach ($ids as $id) {
            $id = (string)$id;
            $row = ['indexer_id' => $id];
            try {
                $outcome = $this->dispatcher->dispatch($id);
                $row['success'] = true;
                $row['mode'] = $outcome['mode'];
                $row['duration_ms'] = $outcome['duration_ms'];
                if ($outcome['mode'] === 'queued') {
                    $queued++;
                }
            } catch (\Throwable $e) {
                $row['success'] = false;
                $row['message'] = $e->getMessage();
            }
            $report[] = $row;
        }

        $ok = count(array_filter($report, static fn ($r) => $r['success']));
        $fail = count($report) - $ok;
        $summary = $queued > 0
            ? __('%1 queued, %2 failed.', $queued, $fail)->render()
            : __('%1 succeeded, %2 failed.', $ok, $fail)->render();

        return $result->setData([
            'success' => $fail === 0,
            'summary' => $summary,
            'rows' => $report,
        ]);
    }
}
