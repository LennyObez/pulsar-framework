<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\RoleRegistryInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Forum\Badge\BadgeServiceInterface;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Config\ForumPermissions;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRenderer;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
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
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

/**
 * Orchestrator that delegates to focused sub-providers for forum service wiring.
 *
 * Sub-providers:
 *  - ForumRepositoryProvider      — all repository interface → implementation bindings
 *  - ForumCoreServiceProvider     — services, content rendering, anti-abuse, notifications
 */
#[Internal(reason: 'Forum service wiring — use interfaces for public API')]
final class ForumServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // 1. Repository layer (DB-backed implementations)
        new ForumRepositoryProvider()->register($container);

        // 2. Core services (reputation, badges, voting, moderation, forum, tags, content, notifications)
        new ForumCoreServiceProvider()->register($container);

        // 3. Permissions
        $this->registerPermissions($container);
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [
            // Repositories
            CategoryRepositoryInterface::class,
            ThreadRepositoryInterface::class,
            PostRepositoryInterface::class,
            ThreadVoteRepositoryInterface::class,
            PostVoteRepositoryInterface::class,
            ThreadReportRepositoryInterface::class,
            PostReportRepositoryInterface::class,
            TagRepositoryInterface::class,
            ForumProfileRepositoryInterface::class,
            UserBadgeRepositoryInterface::class,
            ThreadSubscriptionRepositoryInterface::class,
            // Services
            ForumServiceInterface::class,
            VoteServiceInterface::class,
            ReputationServiceInterface::class,
            ModerationServiceInterface::class,
            TagServiceInterface::class,
            BadgeServiceInterface::class,
            // Content rendering
            MarkdownRenderer::class,
            MarkdownRendererInterface::class,
            ForumBodyPolicy::class,
            // Anti-abuse
            ForumAntiAbuseMiddleware::class,
            // Notifications
            ForumNotificationDispatcher::class,
        ];
    }

    private function registerPermissions(ContainerInterface $container): void
    {
        if ($container->has(RoleRegistryInterface::class)) {
            /** @var RoleRegistryInterface $roleRegistry */
            $roleRegistry = $container->get(RoleRegistryInterface::class);
            ForumPermissions::register($roleRegistry);
        }
    }
}
