<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Area;
use Psr\Log\LoggerInterface;

class Notifier
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function notifyFailure(RunLog $log): void
    {
        if (!$this->config->isNotifyOnFailure()) {
            return;
        }
        $emails = $this->config->getNotifyEmails();
        if (!$emails) {
            return;
        }

        try {
            $sender = (string)$this->scopeConfig->getValue('trans_email/ident_general/email')
                ?: 'noreply@example.com';
            $senderName = (string)$this->scopeConfig->getValue('trans_email/ident_general/name')
                ?: 'Magento';

            $vars = [
                'indexer_id'  => (string)$log->getData('indexer_id'),
                'operation'   => (string)$log->getData('operation'),
                'context'     => (string)$log->getData('context'),
                'started_at'  => (string)$log->getData('started_at'),
                'finished_at' => (string)$log->getData('finished_at'),
                'duration_ms' => (int)$log->getData('duration_ms'),
                'admin_user'  => (string)($log->getData('admin_user') ?? '-'),
                'message'     => (string)$log->getData('message'),
                'store_name'  => $this->storeManager->getStore()->getName(),
                'store_url'   => $this->storeManager->getStore()->getBaseUrl(),
            ];

            $body = $this->buildBody($vars);
            $subject = sprintf('[Indexer Manager] %s reindex failed on %s', $vars['indexer_id'], $vars['store_name']);

            $transport = $this->transportBuilder
                ->setTemplateIdentifier('panth_indexer_manager_failure')
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $this->storeManager->getStore()->getId(),
                ])
                ->setTemplateVars($vars + ['body_html' => $body, 'subject' => $subject])
                ->setFromByScope(['email' => $sender, 'name' => $senderName])
                ->addTo($emails)
                ->getTransport();

            $transport->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->warning('[Panth IndexerManager] failure email failed: ' . $e->getMessage());
        }
    }

    private function buildBody(array $v): string
    {
        return '<h2>Reindex failure</h2>'
            . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;">'
            . '<tr><td><b>Indexer</b></td><td>' . htmlspecialchars($v['indexer_id']) . '</td></tr>'
            . '<tr><td><b>Operation</b></td><td>' . htmlspecialchars($v['operation']) . '</td></tr>'
            . '<tr><td><b>Context</b></td><td>' . htmlspecialchars($v['context']) . '</td></tr>'
            . '<tr><td><b>Started</b></td><td>' . htmlspecialchars($v['started_at']) . '</td></tr>'
            . '<tr><td><b>Finished</b></td><td>' . htmlspecialchars($v['finished_at']) . '</td></tr>'
            . '<tr><td><b>Duration</b></td><td>' . (int)$v['duration_ms'] . ' ms</td></tr>'
            . '<tr><td><b>Admin user</b></td><td>' . htmlspecialchars($v['admin_user']) . '</td></tr>'
            . '<tr><td><b>Store</b></td><td>' . htmlspecialchars($v['store_name']) . '</td></tr>'
            . '</table>'
            . '<h3>Error</h3>'
            . '<pre style="background:#f5f5f5;padding:10px;border-radius:4px;white-space:pre-wrap;">'
            . htmlspecialchars($v['message']) . '</pre>'
            . '<p><a href="' . htmlspecialchars($v['store_url']) . '">' . htmlspecialchars($v['store_url']) . '</a></p>';
    }
}
