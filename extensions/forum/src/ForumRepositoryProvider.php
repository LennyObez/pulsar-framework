<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum;

use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Forum\Badge\UserBadgeRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryRepositoryInterface;
use Pulsar\Extension\Forum\Category\CategoryTranslationRepositoryInterface;
use Pulsar\Extension\Forum\Internal\Persistence\DbCategoryRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbCategoryTranslationRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbForumProfileRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbPostReportRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbPostRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbPostVoteRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbTagRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadReportRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadSubscriptionRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbThreadVoteRepository;
use Pulsar\Extension\Forum\Internal\Persistence\DbUserBadgeRepository;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;

/**
 * Binds all forum repository interfaces to their database-backed implementations.
 */
#[Internal(reason: 'Forum service wiring — use interfaces for public API')]
final readonly class ForumRepositoryProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        /** @var string|null $tenantId */
        $tenantId = null;

        $container->instance(
            CategoryRepositoryInterface::class,
            new DbCategoryRepository($connection, $tenantId),
        );

        $container->instance(
            CategoryTranslationRepositoryInterface::class,
            new DbCategoryTranslationRepository($connection),
        );

        $container->instance(
            ThreadRepositoryInterface::class,
            new DbThreadRepository($connection, $tenantId),
        );

        $container->instance(
            PostRepositoryInterface::class,
            new DbPostRepository($connection, $tenantId),
        );

        $container->instance(
            ThreadVoteRepositoryInterface::class,
            new DbThreadVoteRepository($connection, $tenantId),
        );

        $container->instance(
            PostVoteRepositoryInterface::class,
            new DbPostVoteRepository($connection, $tenantId),
        );

        $container->instance(
            ThreadReportRepositoryInterface::class,
            new DbThreadReportRepository($connection, $tenantId),
        );

        $container->instance(
            PostReportRepositoryInterface::class,
            new DbPostReportRepository($connection, $tenantId),
        );

        $container->instance(
            TagRepositoryInterface::class,
            new DbTagRepository($connection),
        );

        $container->instance(
            ForumProfileRepositoryInterface::class,
            new DbForumProfileRepository($connection, $tenantId),
        );

        $container->instance(
            UserBadgeRepositoryInterface::class,
            new DbUserBadgeRepository($connection, $tenantId),
        );

        $container->instance(
            ThreadSubscriptionRepositoryInterface::class,
            new DbThreadSubscriptionRepository($connection, $tenantId),
        );
    }
}
