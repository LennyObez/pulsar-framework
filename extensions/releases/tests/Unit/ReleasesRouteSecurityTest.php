<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Releases\ReleasesExtension;
use Pulsar\Routing\Router;

use function str_starts_with;

/**
 * The same regression AdminRouteSecurityTest was written for, in another extension:
 * admin routes registered through the bare router sugar, which attaches no middleware,
 * so the panel served unauthenticated CRUD. It was fixed in extensions/admin and never
 * carried across to this one.
 *
 * Here the exposure was concrete. An anonymous GET on /admin/releases/beta-signups
 * returned every beta subscriber's email address, and an anonymous POST on
 * /admin/releases set the download URL that the public /api/v1/version endpoint hands
 * to clients — a redirect of the update channel itself.
 */
#[CoversClass(ReleasesExtension::class)]
final class ReleasesRouteSecurityTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        new ReleasesExtension()->boot($container, $this->router);
    }

    #[Test]
    public function everyAdminRouteRequiresAuthorization(): void
    {
        $admin = $this->adminRoutes();

        self::assertNotSame([], $admin, 'the admin routes must be registered at all');

        foreach ($admin as $route) {
            self::assertContains(
                'auth',
                $route->middleware,
                "Route '{$route->name}' ({$route->path}) is reachable without authentication",
            );
        }
    }

    /**
     * The `auth` alias alone is not enough. AuthorizationMiddleware default-denies a
     * route that declares no permissions, so an unannotated route fails closed — but
     * it fails closed for everyone, which is a broken feature rather than a guarded
     * one. Both halves have to be present.
     */
    #[Test]
    public function everyAdminRouteDeclaresAPermission(): void
    {
        foreach ($this->adminRoutes() as $route) {
            /** @var list<string> $permissions */
            $permissions = $route->attributes['permissions'] ?? [];

            self::assertNotSame(
                [],
                $permissions,
                "Route '{$route->name}' ({$route->path}) declares no permission",
            );
        }
    }

    /**
     * Subscriber email addresses are personal data, so reading them is not the same
     * grant as reading the release list.
     */
    #[Test]
    public function theSubscriberListNeedsItsOwnPermission(): void
    {
        $route = $this->router->namedRoutes['releases.admin.beta_signups'] ?? null;

        self::assertNotNull($route);
        self::assertSame(['releases.beta_signups.read'], $route->attributes['permissions'] ?? []);
    }

    /**
     * @return list<\Pulsar\Routing\Route>
     */
    private function adminRoutes(): array
    {
        $admin = [];

        foreach ($this->router->routes as $route) {
            if (str_starts_with($route->path, '/admin/')) {
                $admin[] = $route;
            }
        }

        return $admin;
    }
}
