<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\ForumRepositoryProvider;
use Pulsar\Extension\Forum\Notification\ForumNotificationRepositoryInterface;
use Pulsar\Extension\Forum\Notification\NotificationPreferenceRepositoryInterface;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[CoversClass(ForumRepositoryProvider::class)]
final class ForumRepositoryProviderTest extends TestCase
{
    #[Test]
    public function registerBindsAllRepositoryInterfaces(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $boundIds = [];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === ConnectionInterface::class,
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id) => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            },
        );
        $container->method('instance')->willReturnCallback(
            static function (string $id, object $instance) use (&$boundIds): void {
                $boundIds[] = $id;
            },
        );

        $provider = new ForumRepositoryProvider();
        $provider->register($container);

        self::assertContains(CategoryRepositoryInterface::class, $boundIds);
        self::assertContains(CategoryTranslationRepositoryInterface::class, $boundIds);
        self::assertContains(ThreadRepositoryInterface::class, $boundIds);
        self::assertContains(PostRepositoryInterface::class, $boundIds);
        self::assertContains(ThreadVoteRepositoryInterface::class, $boundIds);
        self::assertContains(PostVoteRepositoryInterface::class, $boundIds);
        self::assertContains(ThreadReportRepositoryInterface::class, $boundIds);
        self::assertContains(PostReportRepositoryInterface::class, $boundIds);
        self::assertContains(TagRepositoryInterface::class, $boundIds);
        self::assertContains(ForumProfileRepositoryInterface::class, $boundIds);
        self::assertContains(UserBadgeRepositoryInterface::class, $boundIds);
        self::assertContains(ThreadSubscriptionRepositoryInterface::class, $boundIds);
        self::assertContains(ForumNotificationRepositoryInterface::class, $boundIds);
        self::assertContains(NotificationPreferenceRepositoryInterface::class, $boundIds);
        self::assertContains(ForumModerationLogRepositoryInterface::class, $boundIds);
        self::assertContains(UserBanRepositoryInterface::class, $boundIds);
    }

    #[Test]
    public function registerBindsSixteenRepositories(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn(string $id): bool => $id === ConnectionInterface::class,
        );
        $container->method('get')->willReturnCallback(
            static fn(string $id) => match ($id) {
                ConnectionInterface::class => $connection,
                default => null,
            },
        );

        $container->expects(self::exactly(16))->method('instance');

        $provider = new ForumRepositoryProvider();
        $provider->register($container);
    }

    #[Test]
    public function registerDoesNothingWithoutConnection(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $this->expectNotToPerformAssertions();

        $provider = new ForumRepositoryProvider();
        $provider->register($container);
    }
}
