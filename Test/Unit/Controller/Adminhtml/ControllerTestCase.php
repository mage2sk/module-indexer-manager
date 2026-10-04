<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Indexer\Model\Indexer;
use Magento\Indexer\Model\IndexerFactory;
use PHPUnit\Framework\TestCase;

/**
 * Shared wiring for admin controllers: records params, JSON payloads, redirects and messages.
 */
abstract class ControllerTestCase extends TestCase
{
    /**
     * @var array
     */
    protected array $params = [];

    /**
     * @var array
     */
    protected array $messages = [];

    /**
     * @var string|null
     */
    protected ?string $redirectPath = null;

    /**
     * @var array|null
     */
    protected ?array $jsonData = null;

    /**
     * @var int|null
     */
    protected ?int $httpCode = null;

    /**
     * Build an admin action context backed by the recording properties.
     *
     * @return Context
     */
    protected function context(): Context
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            fn($key, $default = null) => $this->params[$key] ?? $default
        );

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) {
            $this->redirectPath = $path;
            return $redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messages = $this->createStub(ManagerInterface::class);
        $map = [
            'addSuccessMessage' => 'success',
            'addErrorMessage' => 'error',
            'addWarningMessage' => 'warning',
        ];
        foreach ($map as $method => $type) {
            $messages->method($method)->willReturnCallback(function ($message) use ($type, $messages) {
                $this->messages[] = [$type, (string)$message];
                return $messages;
            });
        }

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messages);
        return $context;
    }

    /**
     * Build a JSON result factory that records data and HTTP code.
     *
     * @return JsonFactory
     */
    protected function jsonFactory(): JsonFactory
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->jsonData = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) {
            $this->httpCode = $code;
            return $json;
        });
        $factory = $this->createStub(JsonFactory::class);
        $factory->method('create')->willReturn($json);
        return $factory;
    }

    /**
     * Build an indexer factory returning the given indexer.
     *
     * @param Indexer $indexer
     * @return IndexerFactory
     */
    protected function indexerFactory(Indexer $indexer): IndexerFactory
    {
        $factory = $this->createStub(IndexerFactory::class);
        $factory->method('create')->willReturn($indexer);
        return $factory;
    }

    /**
     * Messages of one type in the order they were added.
     *
     * @param string $type
     * @return string[]
     */
    protected function messagesOf(string $type): array
    {
        return array_values(array_map(
            static fn($m) => $m[1],
            array_filter($this->messages, static fn($m) => $m[0] === $type)
        ));
    }
}
