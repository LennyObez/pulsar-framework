<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extension\Releases\ReleasesExtension;
use Pulsar\Extension\Releases\ReleasesServiceProvider;
use Pulsar\Routing\RouterInterface;

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

    #[Test]
    public function bootRegistersApiAndAdminRoutes(): void
    {
        $container = $this->createStub(ContainerInterface::class);

        /** @var RouterInterface&MockObject $router */
        $router = $this->createMock(RouterInterface::class);

        // API: latest_version (GET), list (GET), beta_signup (POST)
        // Admin: index (GET), create (GET), store (POST), edit (GET), update (PUT), beta_signups (GET)
        // Total: get() 6 times, post() 2 times, put() 1 time
        $router->expects(self::exactly(6))
            ->method('get')
            ->willReturnSelf();

        $router->expects(self::exactly(2))
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

        $router->expects(self::exactly(6))
            ->method('get')
            ->willReturnCallback(function (string $path, mixed $handler, ?string $name = null) use ($router, &$getCalls): RouterInterface {
                $getCalls[] = ['path' => $path, 'name' => $name];

                return $router;
            });

        $router->expects(self::exactly(2))
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
        self::assertSame('/api/v1/version', $getCalls[0]['path']);
        self::assertSame('releases.api.latest_version', $getCalls[0]['name']);

        self::assertSame('/api/v1/releases', $getCalls[1]['path']);
        self::assertSame('releases.api.list', $getCalls[1]['name']);

        self::assertSame('/api/v1/beta/signup', $postCalls[0]['path']);
        self::assertSame('releases.api.beta_signup', $postCalls[0]['name']);

        // Admin routes
        self::assertSame('/admin/releases', $getCalls[2]['path']);
        self::assertSame('releases.admin.index', $getCalls[2]['name']);

        self::assertSame('/admin/releases/create', $getCalls[3]['path']);
        self::assertSame('releases.admin.create', $getCalls[3]['name']);

        self::assertSame('/admin/releases', $postCalls[1]['path']);
        self::assertSame('releases.admin.store', $postCalls[1]['name']);

        self::assertSame('/admin/releases/{id}', $getCalls[4]['path']);
        self::assertSame('releases.admin.edit', $getCalls[4]['name']);

        self::assertSame('/admin/releases/{id}', $putCalls[0]['path']);
        self::assertSame('releases.admin.update', $putCalls[0]['name']);

        self::assertSame('/admin/releases/beta-signups', $getCalls[5]['path']);
        self::assertSame('releases.admin.beta_signups', $getCalls[5]['name']);
    }
}
