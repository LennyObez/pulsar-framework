<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Security\ProductionSafetyMode;
use Pulsar\Extension\Studio\Server\Controller\ExceptionExplorerController;

#[CoversClass(ExceptionExplorerController::class)]
final class ExceptionExplorerControllerTest extends TestCase
{
    #[Test]
    public function classIsInstantiable(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $safetyMode = new ProductionSafetyMode(EnvironmentMode::Local);
        $controller = new ExceptionExplorerController($store, $safetyMode);

        self::assertInstanceOf(ExceptionExplorerController::class, $controller);
    }
}
