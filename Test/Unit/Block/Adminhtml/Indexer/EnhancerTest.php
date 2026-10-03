<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Block\Adminhtml\Indexer;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\UrlInterface;
use Panth\IndexerManager\Block\Adminhtml\Indexer\Enhancer;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\ViewModel\PollSettings;
use Panth\IndexerManager\Test\Unit\Block\ObjectManagerStubTrait;
use PHPUnit\Framework\TestCase;

class EnhancerTest extends TestCase
{
    use ObjectManagerStubTrait;

    protected function setUp(): void
    {
        $this->installObjectManagerStub();
    }

    protected function tearDown(): void
    {
        $this->restoreObjectManager();
    }

    private function block(array $data = []): Enhancer
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static fn($route) => 'https://admin.test/' . $route);
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);
        return new Enhancer($context, $data);
    }

    public function testEndpointUrls(): void
    {
        $block = $this->block();
        $this->assertSame('https://admin.test/panth_indexer_manager/manage/status', $block->getStatusUrl());
        $this->assertSame('https://admin.test/panth_indexer_manager/manage/run', $block->getRunUrl());
        $this->assertSame('https://admin.test/panth_indexer_manager/manage/massRun', $block->getMassRunUrl());
        $this->assertSame('https://admin.test/panth_indexer_manager/manage/details', $block->getDetailsUrl());
        $this->assertSame('https://admin.test/panth_indexer_manager/manage/mode', $block->getModeUrl());
        $this->assertSame('https://admin.test/panth_indexer_manager/log/index', $block->getLogUrl());
    }

    public function testPollIntervalComesFromViewModel(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getPollInterval')->willReturn(17);

        $block = $this->block(['poll_settings' => new PollSettings($config)]);

        $this->assertSame(17, $block->getPollInterval());
    }

    public function testPollIntervalDefaultsWithoutViewModel(): void
    {
        $this->assertSame(Config::POLL_INTERVAL_DEFAULT, $this->block()->getPollInterval());
        $this->assertSame(
            Config::POLL_INTERVAL_DEFAULT,
            $this->block(['poll_settings' => 'not a view model'])->getPollInterval()
        );
    }
}
