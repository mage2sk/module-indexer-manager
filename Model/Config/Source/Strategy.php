<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Strategy implements OptionSourceInterface
{
    public const STANDARD = 'standard';
    public const QUEUE = 'queue';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::STANDARD, 'label' => __('Standard (synchronous)')],
            ['value' => self::QUEUE, 'label' => __('Queue (deferred)')],
        ];
    }
}
