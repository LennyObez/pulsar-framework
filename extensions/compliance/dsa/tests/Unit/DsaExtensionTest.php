<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Container\Container;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Dsa\DsaExtension;
use Pulsar\Extension\Dsa\DsaServiceProvider;
use Pulsar\Extension\Dsa\Mapping\DsaMapping;
use Pulsar\Routing\Router;
use Pulsar\Routing\RouterInterface;

use function count;

#[CoversClass(DsaExtension::class)]
final class DsaExtensionTest extends TestCase
{
    private DsaExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new DsaExtension();
    }

    #[Test]
    public function nameReturnsPulsarDsa(): void
    {
        self::assertSame('pulsar/dsa', $this->extension->name());
    }

    #[Test]
    public function registerDoesNotThrow(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        self::assertTrue(true, 'register() completed without exception');
    }

    #[Test]
    public function bootDoesNotRegisterRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method(self::anything());

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function providersReturnsDsaServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(DsaServiceProvider::class, $providers[0]);
    }

    /**
     * The reason this extension has a boot body at all.
     *
     * DsaMapping declared ten controls and nothing anywhere registered them: a
     * grep for its name across src/ and extensions/ returned its own class
     * declaration and nothing else. Controls nobody can reach are claims nobody
     * can check, which is ADR-0041's defect with the failure mode moved from
     * wrong to silent.
     */
    #[Test]
    public function bootRegistersTheDsaControlsWithTheCatalog(): void
    {
        $container = new Container();
        $catalog = new ControlCatalog();
        $container->instance(ControlCatalog::class, $catalog);

        $this->extension->boot($container, new Router());

        // boot() must CONTRIBUTE the mapping, not read the catalog: a read builds
        // every declared control, and this runs on every boot of every deployment
        // that installs the extension. That nothing has read it yet is observable
        // here — a catalog that had been read refuses further contributions.
        $catalog->contribute(static fn(): array => []);

        self::assertSame(count(DsaMapping::declarations()), $catalog->count());
        self::assertNotSame([], $catalog->byFramework(ComplianceFramework::Dsa));
    }

    /**
     * A MicroKernel deployment wires no compliance at all. Booting into one must
     * not fatal, so the registration is conditional on the catalog being bound.
     */
    #[Test]
    public function bootIsANoOpWhenNoCatalogIsBound(): void
    {
        $container = new Container();

        $this->extension->boot($container, new Router());

        self::assertFalse($container->has(ControlCatalog::class));
    }
}
