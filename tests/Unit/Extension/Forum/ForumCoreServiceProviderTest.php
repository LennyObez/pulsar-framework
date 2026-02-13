<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRenderer;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\ForumCoreServiceProvider;
use Pulsar\Extension\Forum\Internal\AntiAbuse\ForumAntiAbuseMiddleware;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Service\TagServiceInterface;
use Pulsar\Extension\Forum\Service\VoteServiceInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

#[CoversClass(ForumCoreServiceProvider::class)]
final class ForumCoreServiceProviderTest extends TestCase
{
    #[Test]
    public function registerDoesNothingWithoutConnection(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $this->expectNotToPerformAssertions();

        // register() should return early without binding anything
        $provider = new ForumCoreServiceProvider();
        $provider->register($container);
    }

    #[Test]
    public function registerBindsAllCoreServices(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $events = $this->createStub(EventDispatcherInterface::class);

        // Build a map of all repository stubs
        $profiles = $this->createStub(ForumProfileRepositoryInterface::class);
        $userBadges = $this->createStub(UserBadgeRepositoryInterface::class);
        $posts = $this->createStub(PostRepositoryInterface::class);
        $threads = $this->createStub(ThreadRepositoryInterface::class);
        $threadVotes = $this->createStub(ThreadVoteRepositoryInterface::class);
        $postVotes = $this->createStub(PostVoteRepositoryInterface::class);
        $threadReports = $this->createStub(ThreadReportRepositoryInterface::class);
        $postReports = $this->createStub(PostReportRepositoryInterface::class);
        $tags = $this->createStub(TagRepositoryInterface::class);
        $subscriptions = $this->createStub(ThreadSubscriptionRepositoryInterface::class);
        $userBans = $this->createStub(UserBanRepositoryInterface::class);
        $moderationLogs = $this->createStub(ForumModerationLogRepositoryInterface::class);

        $container = $this->createStub(ContainerInterface::class);

        /** @var array<string, object> $instanceMap */
        $instanceMap = [];
        $container->method('has')->willReturnCallback(static function (string $id) use (&$instanceMap): bool {
            return match ($id) {
                ConnectionInterface::class, EventDispatcherInterface::class => true,
                ForumConfig::class => false,
                default => isset($instanceMap[$id]),
            };
        });

        $container->method('get')->willReturnCallback(
            static function (string $id) use (
                &$instanceMap,
                $connection,
                $events,
                $profiles,
                $userBadges,
                $posts,
                $threads,
                $threadVotes,
                $postVotes,
                $threadReports,
                $postReports,
                $tags,
                $subscriptions,
                $userBans,
                $moderationLogs,
            ) {
                if (isset($instanceMap[$id])) {
                    return $instanceMap[$id];
                }

                return match ($id) {
                    ConnectionInterface::class => $connection,
                    EventDispatcherInterface::class => $events,
                    ForumProfileRepositoryInterface::class => $profiles,
                    UserBadgeRepositoryInterface::class => $userBadges,
                    PostRepositoryInterface::class => $posts,
                    ThreadRepositoryInterface::class => $threads,
                    ThreadVoteRepositoryInterface::class => $threadVotes,
                    PostVoteRepositoryInterface::class => $postVotes,
                    ThreadReportRepositoryInterface::class => $threadReports,
                    PostReportRepositoryInterface::class => $postReports,
                    TagRepositoryInterface::class => $tags,
                    ThreadSubscriptionRepositoryInterface::class => $subscriptions,
                    UserBanRepositoryInterface::class => $userBans,
                    ForumModerationLogRepositoryInterface::class => $moderationLogs,
                    CategoryRepositoryInterface::class => null,
                    default => null,
                };
            },
        );

        $container->method('instance')->willReturnCallback(
            static function (string $id, object $instance) use (&$instanceMap): void {
                $instanceMap[$id] = $instance;
            },
        );

        $provider = new ForumCoreServiceProvider();
        $provider->register($container);

        // Verify all expected service bindings were registered
        self::assertArrayHasKey(MarkdownRenderer::class, $instanceMap);
        self::assertArrayHasKey(MarkdownRendererInterface::class, $instanceMap);
        self::assertArrayHasKey(ForumBodyPolicy::class, $instanceMap);
        self::assertArrayHasKey(ForumAntiAbuseMiddleware::class, $instanceMap);
        self::assertArrayHasKey(ReputationServiceInterface::class, $instanceMap);
        self::assertArrayHasKey(BadgeServiceInterface::class, $instanceMap);
        self::assertArrayHasKey(VoteServiceInterface::class, $instanceMap);
        self::assertArrayHasKey(ModerationServiceInterface::class, $instanceMap);
        self::assertArrayHasKey(TagServiceInterface::class, $instanceMap);
        self::assertArrayHasKey(ForumServiceInterface::class, $instanceMap);
        self::assertArrayHasKey(ForumNotificationDispatcher::class, $instanceMap);
    }
}
