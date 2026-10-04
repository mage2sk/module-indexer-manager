<?php
declare(strict_types=1);

namespace Panth\IndexerManager\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Panth\IndexerManager\Model\Config;

class PollSettings implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getPollInterval(): int
    {
        return $this->config->getPollInterval();
    }
}
