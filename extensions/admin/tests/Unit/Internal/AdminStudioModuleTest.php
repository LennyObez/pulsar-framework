<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\AdminStudioModule;
use Pulsar\Extension\Studio\Contracts\StudioNavEntry;
use Pulsar\Routing\RouterInterface;

final class AdminStudioModuleTest extends TestCase
{
    private AdminStudioModule $module;

    protected function setUp(): void
    {
        $this->module = new AdminStudioModule();
    }

    #[Test]
    public function module_id(): void
    {
        self::assertSame('admin', $this->module->moduleId());
    }

    #[Test]
    public function label(): void
    {
        self::assertSame('Admin Panel', $this->module->label());
    }

    #[Test]
    public function icon(): void
    {
        self::assertSame('shield', $this->module->icon());
    }

    #[Test]
    public function nav_entries(): void
    {
        $entries = $this->module->navEntries();

        self::assertCount(3, $entries);
        self::assertContainsOnlyInstancesOf(StudioNavEntry::class, $entries);

        self::assertSame('Dashboard', $entries[0]->label);
        self::assertSame('/admin', $entries[0]->href);
        self::assertSame(0, $entries[0]->order);

        self::assertSame('Resources', $entries[1]->label);
        self::assertSame('/admin/resources', $entries[1]->href);
        self::assertSame(1, $entries[1]->order);

        self::assertSame('Activity log', $entries[2]->label);
        self::assertSame('/admin/history', $entries[2]->href);
        self::assertSame(2, $entries[2]->order);
    }

    #[Test]
    public function route_prefix(): void
    {
        self::assertSame('/studio/admin', $this->module->routePrefix());
    }

    #[Test]
    public function nav_order(): void
    {
        self::assertSame(50, $this->module->navOrder());
    }

    #[Test]
    public function register_routes_is_noop(): void
    {
        $router = $this->createStub(RouterInterface::class);

        // Should not throw; routes are registered by AdminExtension directly
        $this->module->registerRoutes($router);

        // registerRoutes on this module is intentionally a no-op
        $this->addToAssertionCount(1);
    }
}
