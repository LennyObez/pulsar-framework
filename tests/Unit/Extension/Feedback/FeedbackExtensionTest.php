<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Feedback\FeedbackExtension;
use Pulsar\Extension\Feedback\FeedbackServiceProvider;
use Pulsar\Routing\RouterInterface;

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

    #[Test]
    public function bootRegistersApiAndAdminRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        // API: submit (POST), index (GET), show (GET)
        // Admin: index (GET), show (GET), updateStatus (PUT), respond (POST), createIssue (POST)
        // Total: get() 4 times, post() 3 times, put() 1 time
        $router->expects(self::exactly(4))
            ->method('get')
            ->willReturnSelf();

        $router->expects(self::exactly(3))
            ->method('post')
            ->willReturnSelf();

        $router->expects(self::once())
            ->method('put')
            ->willReturnSelf();

        $this->extension->boot($container, $router);
    }

    #[Test]
    public function bootRegistersCorrectRoutePaths(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $getCalls = [];
        $postCalls = [];
        $putCalls = [];

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        $router->expects(self::exactly(4))
            ->method('get')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$getCalls): RouterInterface {
                $getCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $router->expects(self::exactly(3))
            ->method('post')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$postCalls): RouterInterface {
                $postCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $router->expects(self::once())
            ->method('put')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$putCalls): RouterInterface {
                $putCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $this->extension->boot($container, $router);

        // API routes
        self::assertSame('/api/v1/feedback', $postCalls[0]['path']);
        self::assertSame('feedback.api.submit', $postCalls[0]['name']);

        self::assertSame('/api/v1/feedback', $getCalls[0]['path']);
        self::assertSame('feedback.api.index', $getCalls[0]['name']);

        self::assertSame('/api/v1/feedback/{id}', $getCalls[1]['path']);
        self::assertSame('feedback.api.show', $getCalls[1]['name']);

        // Admin routes
        self::assertSame('/admin/feedback', $getCalls[2]['path']);
        self::assertSame('feedback.admin.index', $getCalls[2]['name']);

        self::assertSame('/admin/feedback/{id}', $getCalls[3]['path']);
        self::assertSame('feedback.admin.show', $getCalls[3]['name']);

        self::assertSame('/admin/feedback/{id}/status', $putCalls[0]['path']);
        self::assertSame('feedback.admin.update_status', $putCalls[0]['name']);

        self::assertSame('/admin/feedback/{id}/respond', $postCalls[1]['path']);
        self::assertSame('feedback.admin.respond', $postCalls[1]['name']);

        self::assertSame('/admin/feedback/{id}/github-issue', $postCalls[2]['path']);
        self::assertSame('feedback.admin.create_issue', $postCalls[2]['name']);
    }
}
