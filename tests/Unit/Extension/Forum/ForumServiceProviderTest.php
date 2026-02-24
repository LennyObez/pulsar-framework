<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\ForumServiceProvider;
use Pulsar\Extension\Forum\Internal\AntiAbuse\ForumAntiAbuseMiddleware;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Service\TagServiceInterface;
use Pulsar\Extension\Forum\Service\VoteServiceInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[CoversClass(ForumServiceProvider::class)]
final class ForumServiceProviderTest extends TestCase
{
    #[Test]
    public function providesReturnsExpectedServiceList(): void
    {
        $provider = new ForumServiceProvider();
        $provides = $provider->provides();

        // Repository interfaces
        self::assertContains(CategoryRepositoryInterface::class, $provides);
        self::assertContains(ThreadRepositoryInterface::class, $provides);
        self::assertContains(PostRepositoryInterface::class, $provides);
        self::assertContains(ThreadVoteRepositoryInterface::class, $provides);
        self::assertContains(PostVoteRepositoryInterface::class, $provides);
        self::assertContains(ThreadReportRepositoryInterface::class, $provides);
        self::assertContains(PostReportRepositoryInterface::class, $provides);
        self::assertContains(TagRepositoryInterface::class, $provides);
        self::assertContains(ForumProfileRepositoryInterface::class, $provides);

        // Service interfaces
        self::assertContains(ForumServiceInterface::class, $provides);
        self::assertContains(VoteServiceInterface::class, $provides);
        self::assertContains(ReputationServiceInterface::class, $provides);
        self::assertContains(ModerationServiceInterface::class, $provides);
        self::assertContains(TagServiceInterface::class, $provides);
        self::assertContains(BadgeServiceInterface::class, $provides);

        // Content
        self::assertContains(MarkdownRendererInterface::class, $provides);
        self::assertContains(ForumBodyPolicy::class, $provides);

        // Anti-abuse
        self::assertContains(ForumAntiAbuseMiddleware::class, $provides);

        // Notifications
        self::assertContains(ForumNotificationDispatcher::class, $provides);
    }

    #[Test]
    public function registerCallsPermissionsWhenRoleRegistryAvailable(): void
    {
        $roleRegistry = $this->createMock(RoleRegistryInterface::class);
        $roleRegistry->expects(self::atLeastOnce())->method('register');

        $connection = $this->createStub(ConnectionInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);

        $container = $this->createStub(ContainerInterface::class);

        // Build a has/get map that provides all needed dependencies
        /** @var array<string, object> $instanceMap */
        $instanceMap = [];
        $container->method('has')->willReturnCallback(static fn(string $id): bool => match ($id) {
            ConnectionInterface::class, RoleRegistryInterface::class, EventDispatcherInterface::class => true,
            default => false,
        });

        $container->method('get')->willReturnCallback(static function (string $id) use (&$instanceMap, $connection, $events, $roleRegistry) {
            if (isset($instanceMap[$id])) {
                return $instanceMap[$id];
            }

            return match ($id) {
                ConnectionInterface::class => $connection,
                EventDispatcherInterface::class => $events,
                RoleRegistryInterface::class => $roleRegistry,
                default => null,
            };
        });

        // Capture instance() calls to build the instance map
        $container->method('instance')->willReturnCallback(static function (string $id, object $instance) use (&$instanceMap): void {
            $instanceMap[$id] = $instance;
        });

        $provider = new ForumServiceProvider();
        $provider->register($container);
    }
}
