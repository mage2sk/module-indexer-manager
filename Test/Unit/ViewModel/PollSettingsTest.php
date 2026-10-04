<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\ViewModel;

use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\ViewModel\PollSettings;
use PHPUnit\Framework\TestCase;

class PollSettingsTest extends TestCase
{
    public function testIntervalComesFromConfig(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getPollInterval')->willReturn(17);
        $this->assertSame(17, (new PollSettings($config))->getPollInterval());
    }
}
