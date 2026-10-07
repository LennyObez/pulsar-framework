<?php

declare(strict_types=1);

namespace Pulsar\Extension\DataAct\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Container\Container;
use Pulsar\Extension\DataAct\DataActExtension;
use Pulsar\Extension\DataAct\DataActServiceProvider;
use Pulsar\Extension\DataAct\Mapping\DataActMapping;
use Pulsar\Routing\Router;

use function count;

#[CoversClass(DataActExtension::class)]
final class DataActExtensionTest extends TestCase
{
    #[Test]
    public function nameReturnsPulsarDataAct(): void
    {
        $extension = new DataActExtension();

        self::assertSame('pulsar/data-act', $extension->name());
    }

    #[Test]
    public function providersIncludesServiceProvider(): void
    {
        $extension = new DataActExtension();

        $providers = $extension->providers();

        self::assertContains(DataActServiceProvider::class, $providers);
        self::assertCount(1, $providers);
    }

    /**
     * DataActMapping declared six controls that nothing registered anywhere, so
     * none of them could be reported and none could be falsified. See
     * DsaExtensionTest for the argument; this is the same defect in the same
     * shape.
     */
    #[Test]
    public function bootRegistersTheDataActControlsWithTheCatalog(): void
    {
        $container = new Container();
        $catalog = new ControlCatalog();
        $container->instance(ControlCatalog::class, $catalog);

        new DataActExtension()->boot($container, new Router());

        // boot() must CONTRIBUTE the mapping, not read the catalog: a read builds
        // every declared control, and this runs on every boot of every deployment
        // that installs the extension. That nothing has read it yet is observable
        // here — a catalog that had been read refuses further contributions.
        $catalog->contribute(static fn(): array => []);

        self::assertSame(count(DataActMapping::declarations()), $catalog->count());
        self::assertNotSame([], $catalog->byFramework(ComplianceFramework::DataAct));
    }

    /**
     * A MicroKernel deployment wires no compliance at all, so the registration is
     * conditional on the catalog being bound and booting into one is a no-op.
     */
    #[Test]
    public function bootIsANoOpWhenNoCatalogIsBound(): void
    {
        $container = new Container();

        new DataActExtension()->boot($container, new Router());

        self::assertFalse($container->has(ControlCatalog::class));
    }
}
