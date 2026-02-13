<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Releases;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\Http\Controller\Admin\ReleaseController as AdminReleaseController;
use Pulsar\Extension\Releases\Http\Controller\Api\BetaSignupController;
use Pulsar\Extension\Releases\Http\Controller\Api\ReleaseApiController;
use Pulsar\Extension\Releases\Internal\ReleaseService;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;
use Pulsar\Extension\Releases\ReleasesServiceProvider;

final class ReleasesServiceProviderTest extends TestCase
{
    private ReleasesServiceProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new ReleasesServiceProvider();
    }

    #[Test]
    public function providesReturnsSixClassStrings(): void
    {
        $provides = $this->provider->provides();

        self::assertCount(6, $provides);
    }

    #[Test]
    public function providesContainsExpectedClasses(): void
    {
        $provides = $this->provider->provides();

        self::assertContains(ReleaseRepositoryInterface::class, $provides);
        self::assertContains(BetaSignupRepositoryInterface::class, $provides);
        self::assertContains(ReleaseService::class, $provides);
        self::assertContains(ReleaseApiController::class, $provides);
        self::assertContains(BetaSignupController::class, $provides);
        self::assertContains(AdminReleaseController::class, $provides);
    }

    #[Test]
    public function registerReturnsEarlyWhenNoConnectionInterface(): void
    {
        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => false,
                default => false,
            });

        $container->expects(self::never())
            ->method('instance');

        $this->provider->register($container);
    }

    #[Test]
    public function registerBindsAllServicesWhenConnectionAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $boundIds = [];

        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            });

        // ReleaseRepositoryInterface, BetaSignupRepositoryInterface,
        // ReleaseService, ReleaseApiController, BetaSignupController, AdminReleaseController
        $container->expects(self::exactly(6))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundIds): void {
                $boundIds[] = $id;
            });

        $this->provider->register($container);

        self::assertContains(ReleaseRepositoryInterface::class, $boundIds);
        self::assertContains(BetaSignupRepositoryInterface::class, $boundIds);
        self::assertContains(ReleaseService::class, $boundIds);
        self::assertContains(ReleaseApiController::class, $boundIds);
        self::assertContains(BetaSignupController::class, $boundIds);
        self::assertContains(AdminReleaseController::class, $boundIds);
    }
}
