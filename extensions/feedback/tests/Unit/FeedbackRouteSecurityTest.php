<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Feedback\FeedbackExtension;
use Pulsar\Routing\Router;

use function str_starts_with;

/**
 * The same regression AdminRouteSecurityTest was written for, in another extension:
 * admin routes registered through the bare router sugar, which attaches no middleware.
 * Fixed in extensions/admin, never carried across to this one.
 *
 * The sharpest case here is not a read. POST /admin/feedback/{id}/github-issue sends
 * the server's stored GitHub token to the repository named in the request, so an
 * unauthenticated caller did not merely see data — they spent a credential.
 */
#[CoversClass(FeedbackExtension::class)]
final class FeedbackRouteSecurityTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();

        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        new FeedbackExtension()->boot($container, $this->router);
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
     * AuthorizationMiddleware default-denies a route with no declared permission, so
     * an unannotated route fails closed — closed for everyone, which is a broken
     * feature rather than a guarded one. Both halves have to be present.
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
     * Spending the server's GitHub credential is a different grant from reading a
     * feedback item, and it is the one an anonymous caller could previously reach.
     */
    #[Test]
    public function creatingAGithubIssueNeedsItsOwnPermission(): void
    {
        $route = $this->router->namedRoutes['feedback.admin.create_issue'] ?? null;

        self::assertNotNull($route);
        self::assertSame(['feedback.github'], $route->attributes['permissions'] ?? []);
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
