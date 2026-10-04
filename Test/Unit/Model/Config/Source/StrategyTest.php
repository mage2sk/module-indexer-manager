<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model\Config\Source;

use Panth\IndexerManager\Model\Config\Source\Strategy;
use PHPUnit\Framework\TestCase;

class StrategyTest extends TestCase
{
    public function testOffersStandardAndQueueOptions(): void
    {
        $options = (new Strategy())->toOptionArray();

        $this->assertSame([Strategy::STANDARD, Strategy::QUEUE], array_column($options, 'value'));
        $this->assertSame('Standard (synchronous)', (string)$options[0]['label']);
        $this->assertSame('Queue (deferred)', (string)$options[1]['label']);
        $this->assertSame('standard', Strategy::STANDARD);
        $this->assertSame('queue', Strategy::QUEUE);
    }
}
