<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Fhir\FhirExtension;
use Pulsar\Extension\Fhir\FhirServiceProvider;
use Pulsar\Routing\RouterInterface;

#[CoversClass(FhirExtension::class)]
final class FhirExtensionTest extends TestCase
{
    private FhirExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new FhirExtension();
    }

    #[Test]
    public function nameReturnsPulsarFhir(): void
    {
        self::assertSame('pulsar/fhir', $this->extension->name());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $this->extension->register($container);

        self::assertTrue(true, 'register() completed without exception');
    }

    #[Test]
    public function bootRegistersFhirRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);

        // metadata, smart-config, search, read, create, update, delete, batch = 8 routes
        $router->expects(self::exactly(4))->method('get');
        $router->expects(self::exactly(2))->method('post');
        $router->expects(self::once())->method('put');
        $router->expects(self::once())->method('delete');

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function providersReturnsFhirServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(FhirServiceProvider::class, $providers[0]);
    }
}
