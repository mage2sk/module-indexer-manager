<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'panth_indexer_manager/general/enabled';
    public const XML_PATH_STRATEGY = 'panth_indexer_manager/general/strategy';
    public const XML_PATH_TRACKING_ENABLED = 'panth_indexer_manager/tracking/enabled';
    public const XML_PATH_TRACKING_FAILURES_ONLY = 'panth_indexer_manager/tracking/track_failures_only';
    public const XML_PATH_TRACKING_RETENTION = 'panth_indexer_manager/tracking/retention_days';
    public const XML_PATH_NOTIFY_ON_FAILURE = 'panth_indexer_manager/notifications/notify_on_failure';
    public const XML_PATH_NOTIFY_EMAIL = 'panth_indexer_manager/notifications/notify_email';

    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getStrategy(): string
    {
        return (string)($this->scopeConfig->getValue(self::XML_PATH_STRATEGY) ?: 'standard');
    }

    public function isTrackingEnabled(): bool
    {
        return $this->isEnabled()
            && $this->scopeConfig->isSetFlag(self::XML_PATH_TRACKING_ENABLED);
    }

    public function isFailuresOnly(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_TRACKING_FAILURES_ONLY);
    }

    public function getRetentionDays(): int
    {
        return (int)$this->scopeConfig->getValue(self::XML_PATH_TRACKING_RETENTION);
    }

    public function isNotifyOnFailure(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NOTIFY_ON_FAILURE);
    }

    public function getNotifyEmails(): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_PATH_NOTIFY_EMAIL);
        if ($raw === '') {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
