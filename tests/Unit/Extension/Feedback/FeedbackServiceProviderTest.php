<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Feedback;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Feedback\FeedbackRepositoryInterface;
use Pulsar\Extension\Feedback\FeedbackServiceProvider;
use Pulsar\Extension\Feedback\Http\Controller\Admin\FeedbackController as AdminFeedbackController;
use Pulsar\Extension\Feedback\Http\Controller\Api\FeedbackApiController;
use Pulsar\Extension\Feedback\Internal\FeedbackService;
use Pulsar\Queue\QueueDriverInterface;

final class FeedbackServiceProviderTest extends TestCase
{
    private FeedbackServiceProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FeedbackServiceProvider();
    }

    #[Test]
    public function providesReturnsFourClassStrings(): void
    {
        $provides = $this->provider->provides();

        self::assertCount(4, $provides);
    }

    #[Test]
    public function providesContainsExpectedClasses(): void
    {
        $provides = $this->provider->provides();

        self::assertContains(FeedbackRepositoryInterface::class, $provides);
        self::assertContains(FeedbackService::class, $provides);
        self::assertContains(FeedbackApiController::class, $provides);
        self::assertContains(AdminFeedbackController::class, $provides);
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

        // FeedbackRepositoryInterface, FeedbackService, FeedbackApiController, AdminFeedbackController
        $container->expects(self::exactly(4))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundIds): void {
                $boundIds[] = $id;
            });

        $this->provider->register($container);

        self::assertContains(FeedbackRepositoryInterface::class, $boundIds);
        self::assertContains(FeedbackService::class, $boundIds);
        self::assertContains(FeedbackApiController::class, $boundIds);
        self::assertContains(AdminFeedbackController::class, $boundIds);
    }

    #[Test]
    public function registerPassesQueueDriverToAdminControllerWhenAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $queueDriver = $this->createStub(QueueDriverInterface::class);

        $boundInstances = [];

        /** @var ContainerInterface&MockObject $container */
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn(string $id): bool => match ($id) {
                ConnectionInterface::class, QueueDriverInterface::class => true,
                default => false,
            });

        $container->method('get')
            ->willReturnCallback(static fn(string $id): mixed => match ($id) {
                ConnectionInterface::class => $connection,
                QueueDriverInterface::class => $queueDriver,
                default => null,
            });

        $container->expects(self::exactly(4))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundInstances): void {
                $boundInstances[$id] = $instance;
            });

        $this->provider->register($container);

        self::assertArrayHasKey(AdminFeedbackController::class, $boundInstances);
        self::assertInstanceOf(AdminFeedbackController::class, $boundInstances[AdminFeedbackController::class]);
    }

    #[Test]
    public function registerPassesNullQueueDriverWhenNotAvailable(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $boundInstances = [];

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

        $container->expects(self::exactly(4))
            ->method('instance')
            ->willReturnCallback(function (string $id, object $instance) use (&$boundInstances): void {
                $boundInstances[$id] = $instance;
            });

        $this->provider->register($container);

        // AdminFeedbackController is created with null queue driver — should still be bound
        self::assertArrayHasKey(AdminFeedbackController::class, $boundInstances);
        self::assertInstanceOf(AdminFeedbackController::class, $boundInstances[AdminFeedbackController::class]);
    }
}
