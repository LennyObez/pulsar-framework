<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Releases\ReleasesExtension;
use Pulsar\Extension\Releases\ReleasesServiceProvider;
use Pulsar\Routing\Router;

final class ReleasesExtensionTest extends TestCase
{
    private ReleasesExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ReleasesExtension();
    }

    #[Test]
    public function nameReturnsPulsarReleases(): void
    {
        self::assertSame('pulsar/releases', $this->extension->name());
    }

    #[Test]
    public function providersReturnsReleasesServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(ReleasesServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        self::assertSame('pulsar/releases', $this->extension->name());
    }

    /**
     * Booted into a real Router rather than a mock counting get/post/put calls.
     *
     * The previous version indexed a per-verb call list, which tied every assertion
     * to which registration method a route happens to use. Moving the admin routes
     * onto Route — the only way to attach the auth middleware and the permission
     * they now require — broke assertions about paths that had not changed at all.
     * A real router asserts the same facts without that coupling.
     */
    #[Test]
    public function bootRegistersEveryRouteAtItsDeclaredPath(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $router = new Router();
        $this->extension->boot($container, $router);

        $expected = [
            'releases.api.latest_version' => '/api/v1/version',
            'releases.api.list' => '/api/v1/releases',
            'releases.api.beta_signup' => '/api/v1/beta/signup',
            'releases.admin.index' => '/admin/releases',
            'releases.admin.create' => '/admin/releases/create',
            'releases.admin.store' => '/admin/releases',
            'releases.admin.edit' => '/admin/releases/{id}',
            'releases.admin.update' => '/admin/releases/{id}',
            'releases.admin.beta_signups' => '/admin/releases/beta-signups',
        ];

        foreach ($expected as $name => $path) {
            $route = $router->namedRoutes[$name] ?? null;

            self::assertNotNull($route, "Route '{$name}' was not registered");
            self::assertSame($path, $route->path);
        }
    }

    #[Test]
    public function bootRegistersNothingBeyondTheDeclaredRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $router = new Router();
        $this->extension->boot($container, $router);

        self::assertCount(9, $router->routes);
    }
}
