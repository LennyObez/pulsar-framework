<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Forum\ForumExtension;
use Pulsar\Routing\Router;

/**
 * Verifies that the forum extension registers all routes under /community
 * and does not register a root `/` route (blocker #1).
 */
final class ForumExtensionRouteTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $container = $this->createStub(ContainerInterface::class);

        $extension = new ForumExtension();
        $extension->boot($container, $this->router);
    }

    #[Test]
    public function forumHomepageRoutePrefixedWithCommunity(): void
    {
        $route = $this->router->namedRoutes['forum.page.home'] ?? null;
        self::assertNotNull($route, 'forum.page.home route must be registered');
        self::assertSame('/community', $route->path);
    }

    #[Test]
    public function noRootSlashRouteRegistered(): void
    {
        foreach ($this->router->routes as $route) {
            self::assertNotSame('/', $route->path, 'Forum must not register a root "/" route');
        }
    }

    #[Test]
    public function categoryRouteHasCommunityPrefix(): void
    {
        $route = $this->router->namedRoutes['forum.page.category'] ?? null;
        self::assertNotNull($route);
        self::assertStringStartsWith('/community/', $route->path);
    }

    #[Test]
    public function threadRouteHasCommunityPrefix(): void
    {
        $route = $this->router->namedRoutes['forum.page.thread'] ?? null;
        self::assertNotNull($route);
        self::assertStringStartsWith('/community/', $route->path);
    }

    #[Test]
    public function authRoutesHaveCommunityPrefix(): void
    {
        $authRoutes = [
            'forum.auth.register',
            'forum.auth.login',
            'forum.auth.forgot_password',
            'forum.auth.reset_password',
        ];

        foreach ($authRoutes as $name) {
            $route = $this->router->namedRoutes[$name] ?? null;
            self::assertNotNull($route, "Route '$name' must be registered");
            self::assertStringStartsWith('/community/', $route->path, "Route '$name' must be prefixed with /community");
        }
    }

    #[Test]
    public function accountRoutesHaveCommunityPrefix(): void
    {
        $accountRoutes = [
            'forum.account.profile',
            'forum.account.threads',
            'forum.account.posts',
            'forum.account.settings',
        ];

        foreach ($accountRoutes as $name) {
            $route = $this->router->namedRoutes[$name] ?? null;
            self::assertNotNull($route, "Route '$name' must be registered");
            self::assertStringStartsWith('/community/', $route->path, "Route '$name' must be prefixed with /community");
        }
    }

    #[Test]
    public function apiRoutesRetainOriginalPrefix(): void
    {
        $route = $this->router->namedRoutes['forum.api.threads.index'] ?? null;
        self::assertNotNull($route);
        self::assertStringStartsWith('/api/v1/forum/', $route->path);
    }

    #[Test]
    public function adminRoutesRetainOriginalPrefix(): void
    {
        $route = $this->router->namedRoutes['forum.admin.dashboard'] ?? null;
        self::assertNotNull($route);
        self::assertSame('/admin/forum', $route->path);
    }
}
