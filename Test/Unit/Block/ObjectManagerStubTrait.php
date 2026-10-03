<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Test\Unit\Block;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;

/**
 * Backend Template pulls optional helpers from the static ObjectManager; install a stub for the
 * duration of a test and restore whatever was there before.
 */
trait ObjectManagerStubTrait
{
    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $previousObjectManager = null;

    /**
     * Install a stub object manager that returns stubs for any requested class.
     *
     * @return void
     */
    private function installObjectManagerStub(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();

        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn($class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    /**
     * Restore the object manager that was active before the test.
     *
     * @return void
     */
    private function restoreObjectManager(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }
}
