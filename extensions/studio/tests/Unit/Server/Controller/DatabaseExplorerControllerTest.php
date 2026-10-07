<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\Server\Controller\DatabaseExplorerController;

#[CoversClass(DatabaseExplorerController::class)]
final class DatabaseExplorerControllerTest extends TestCase
{
    #[Test]
    public function classIsInstantiable(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $controller = new DatabaseExplorerController($store);

        self::assertInstanceOf(DatabaseExplorerController::class, $controller);
    }
}
