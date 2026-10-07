<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Feedback\FeedbackExtension;
use Pulsar\Extension\Feedback\FeedbackServiceProvider;
use Pulsar\Routing\Router;

final class FeedbackExtensionTest extends TestCase
{
    private FeedbackExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new FeedbackExtension();
    }

    #[Test]
    public function nameReturnsPulsarFeedback(): void
    {
        self::assertSame('pulsar/feedback', $this->extension->name());
    }

    #[Test]
    public function providersReturnsFeedbackServiceProvider(): void
    {
        $providers = $this->extension->providers();

        self::assertCount(1, $providers);
        self::assertSame(FeedbackServiceProvider::class, $providers[0]);
    }

    #[Test]
    public function registerIsNoOp(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $this->extension->register($container);

        self::assertSame('pulsar/feedback', $this->extension->name());
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
            'feedback.api.submit' => '/api/v1/feedback',
            'feedback.api.index' => '/api/v1/feedback',
            'feedback.api.show' => '/api/v1/feedback/{id}',
            'feedback.admin.index' => '/admin/feedback',
            'feedback.admin.show' => '/admin/feedback/{id}',
            'feedback.admin.update_status' => '/admin/feedback/{id}/status',
            'feedback.admin.respond' => '/admin/feedback/{id}/respond',
            'feedback.admin.create_issue' => '/admin/feedback/{id}/github-issue',
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

        self::assertCount(8, $router->routes);
    }
}
