<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Panth\IndexerManager\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = []): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn($path) => $values[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(static fn($path) => (bool)($flags[$path] ?? false));
        return new Config($scopeConfig);
    }

    public static function intervals(): array
    {
        return [
            'default when empty' => [null, 5],
            'configured' => ['30', 30],
            'below minimum' => ['1', 2],
            'above maximum' => ['99999', 3600],
            'not numeric' => ['abc', 5],
            'zero' => ['0', 5],
            'negative' => ['-10', 5],
            'exact minimum' => ['2', 2],
            'exact maximum' => ['3600', 3600],
        ];
    }

    #[DataProvider('intervals')]
    public function testPollInterval(?string $raw, int $expected): void
    {
        $config = $this->config([Config::XML_PATH_POLL_INTERVAL => $raw]);
        $this->assertSame($expected, $config->getPollInterval());
    }

    public function testEnabledFlag(): void
    {
        $this->assertTrue($this->config([], [Config::XML_PATH_ENABLED => true])->isEnabled());
        $this->assertFalse($this->config()->isEnabled());
    }

    public function testStrategyDefaultsToStandard(): void
    {
        $this->assertSame('standard', $this->config()->getStrategy());
        $this->assertSame('standard', $this->config([Config::XML_PATH_STRATEGY => ''])->getStrategy());
        $this->assertSame('queue', $this->config([Config::XML_PATH_STRATEGY => 'queue'])->getStrategy());
    }

    public static function trackingFlags(): array
    {
        return [
            'both on' => [true, true, true],
            'module off' => [false, true, false],
            'tracking off' => [true, false, false],
            'both off' => [false, false, false],
        ];
    }

    #[DataProvider('trackingFlags')]
    public function testTrackingRequiresModuleAndTrackingFlag(bool $enabled, bool $tracking, bool $expected): void
    {
        $config = $this->config([], [
            Config::XML_PATH_ENABLED => $enabled,
            Config::XML_PATH_TRACKING_ENABLED => $tracking,
        ]);
        $this->assertSame($expected, $config->isTrackingEnabled());
    }

    public function testSimpleFlagsAndRetention(): void
    {
        $config = $this->config(
            [Config::XML_PATH_TRACKING_RETENTION => '30'],
            [Config::XML_PATH_TRACKING_FAILURES_ONLY => true, Config::XML_PATH_NOTIFY_ON_FAILURE => true]
        );
        $this->assertTrue($config->isFailuresOnly());
        $this->assertTrue($config->isNotifyOnFailure());
        $this->assertSame(30, $config->getRetentionDays());

        $empty = $this->config();
        $this->assertFalse($empty->isFailuresOnly());
        $this->assertFalse($empty->isNotifyOnFailure());
        $this->assertSame(0, $empty->getRetentionDays());
    }

    public function testNotifyEmailsAreTrimmedAndBlanksDropped(): void
    {
        $config = $this->config([Config::XML_PATH_NOTIFY_EMAIL => ' a@example.com, ,b@example.com ,']);
        $this->assertSame(['a@example.com', 'b@example.com'], array_values($config->getNotifyEmails()));
    }

    public function testNotifyEmailsEmptyWhenUnset(): void
    {
        $this->assertSame([], $this->config()->getNotifyEmails());
        $this->assertSame([], $this->config([Config::XML_PATH_NOTIFY_EMAIL => ''])->getNotifyEmails());
    }
}
