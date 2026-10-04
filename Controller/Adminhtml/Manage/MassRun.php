<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Controller\Adminhtml\Manage;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\IndexerManager\Controller\Adminhtml\Manage;
use Panth\IndexerManager\Model\Queue\ReindexDispatcher;

class MassRun extends Manage implements HttpPostActionInterface
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
        $immediate = $ok - $queued;
        if ($queued > 0 && $immediate > 0) {
            $summary = __('%1 succeeded, %2 queued, %3 failed.', $immediate, $queued, $fail)->render();
        } elseif ($queued > 0) {
            $summary = __('%1 queued, %2 failed.', $queued, $fail)->render();
        } else {
            $summary = __('%1 succeeded, %2 failed.', $ok, $fail)->render();
        }

        return $result->setData([
            'success' => $fail === 0,
            'summary' => $summary,
            'rows' => $report,
        ]);
    }
}
