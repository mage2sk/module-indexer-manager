<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\IndexerManager\Model\Config;
use Panth\IndexerManager\Model\Notifier;
use Panth\IndexerManager\Model\RunLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NotifierTest extends TestCase
{
    /**
     * @var array
     */
    private array $built = [];

    /**
     * @var string[]
     */
    private array $warnings = [];

    /**
     * @var int
     */
    private int $sent = 0;

    private function log(array $data): RunLog
    {
        $log = new class extends RunLog {
            public function __construct()
            {
            }
        };
        $log->setData($data);
        return $log;
    }

    private function notifier(array $opts = []): Notifier
    {
        $config = $this->createStub(Config::class);
        $config->method('isNotifyOnFailure')->willReturn($opts['notify'] ?? true);
        $config->method('getNotifyEmails')->willReturn($opts['emails'] ?? ['ops@example.com']);

        $transport = $this->createStub(TransportInterface::class);
        $transport->method('sendMessage')->willReturnCallback(function () use ($opts) {
            if (isset($opts['sendError'])) {
                throw $opts['sendError'];
            }
            $this->sent++;
        });

        $builder = $this->createStub(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $m) {
            $builder->method($m)->willReturnCallback(function ($arg) use ($builder, $m) {
                $this->built[$m] = $arg;
                return $builder;
            });
        }
        $builder->method('getTransport')->willReturn($transport);

        $store = $this->createStub(Store::class);
        $store->method('getName')->willReturn('Main <Store>');
        $store->method('getBaseUrl')->willReturn('https://shop.test/');
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(static fn($path) => $opts['scope'][$path] ?? null);

        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($m) {
            $this->warnings[] = $m;
        });

        return new Notifier($config, $builder, $storeManager, $scopeConfig, $logger);
    }

    public function testDoesNothingWhenNotificationsDisabled(): void
    {
        $this->notifier(['notify' => false])->notifyFailure($this->log([]));
        $this->assertSame([], $this->built);
        $this->assertSame(0, $this->sent);
    }

    public function testDoesNothingWithoutRecipients(): void
    {
        $this->notifier(['emails' => []])->notifyFailure($this->log([]));
        $this->assertSame([], $this->built);
        $this->assertSame(0, $this->sent);
    }

    public function testSendsFailureEmailWithEscapedBody(): void
    {
        $this->notifier([
            'emails' => ['a@example.com', 'b@example.com'],
            'scope' => [
                'trans_email/ident_general/email' => 'shop@example.com',
                'trans_email/ident_general/name' => 'Shop',
            ],
        ])->notifyFailure($this->log([
            'indexer_id' => 'catalog_product_price',
            'operation' => 'reindexAll',
            'context' => 'cron',
            'started_at' => '2026-10-01 10:00:00',
            'finished_at' => '2026-10-01 10:00:05',
            'duration_ms' => '5000',
            'message' => 'Error <b>bad</b> & worse',
        ]));

        $this->assertSame(1, $this->sent);
        $this->assertSame('panth_indexer_manager_failure', $this->built['setTemplateIdentifier']);
        $this->assertSame(['area' => 'frontend', 'store' => 1], $this->built['setTemplateOptions']);
        $this->assertSame(['email' => 'shop@example.com', 'name' => 'Shop'], $this->built['setFromByScope']);
        $this->assertSame(['a@example.com', 'b@example.com'], $this->built['addTo']);

        $vars = $this->built['setTemplateVars'];
        $this->assertSame('-', $vars['admin_user']);
        $this->assertSame(5000, $vars['duration_ms']);
        $this->assertSame('[Indexer Manager] catalog_product_price reindex failed on Main <Store>', $vars['subject']);
        $this->assertStringContainsString('Error &lt;b&gt;bad&lt;/b&gt; &amp; worse', $vars['body_html']);
        $this->assertStringContainsString('Main &lt;Store&gt;', $vars['body_html']);
        $this->assertStringContainsString('<td>5000 ms</td>', $vars['body_html']);
        $this->assertStringContainsString('href="https://shop.test/"', $vars['body_html']);
        $this->assertStringNotContainsString('<b>bad</b>', $vars['body_html']);
    }

    public function testSenderFallsBackToDefaults(): void
    {
        $this->notifier()->notifyFailure($this->log(['indexer_id' => 'x', 'admin_user' => 'jane']));

        $this->assertSame(['email' => 'noreply@example.com', 'name' => 'Magento'], $this->built['setFromByScope']);
        $this->assertSame('jane', $this->built['setTemplateVars']['admin_user']);
    }

    public function testTransportErrorsAreLoggedNotThrown(): void
    {
        $this->notifier(['sendError' => new \RuntimeException('smtp down')])->notifyFailure($this->log([]));

        $this->assertSame(['[Panth IndexerManager] failure email failed: smtp down'], $this->warnings);
    }
}
