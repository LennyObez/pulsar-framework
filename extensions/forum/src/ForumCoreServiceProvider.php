<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
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
use Pulsar\Extension\Forum\Internal\AntiAbuse\ForumAntiAbuseMiddleware;
use Pulsar\Extension\Forum\Internal\Notification\BadgeEvaluator;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\Extension\Forum\Internal\Service\AutoModerationService;
use Pulsar\Extension\Forum\Internal\Service\BadgeService;
use Pulsar\Extension\Forum\Internal\Service\BanService;
use Pulsar\Extension\Forum\Internal\Service\ForumSearchService;
use Pulsar\Extension\Forum\Internal\Service\ForumService;
use Pulsar\Extension\Forum\Internal\Service\LeaderboardService;
use Pulsar\Extension\Forum\Internal\Service\ModerationService;
use Pulsar\Extension\Forum\Internal\Service\PrivilegeChecker;
use Pulsar\Extension\Forum\Internal\Service\ReputationService;
use Pulsar\Extension\Forum\Internal\Service\TagService;
use Pulsar\Extension\Forum\Internal\Service\VoteService;
use Pulsar\Extension\Forum\Post\PostRepositoryInterface;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ForumModerationLogRepositoryInterface;
use Pulsar\Extension\Forum\Report\PostReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Report\UserBanRepositoryInterface;
use Pulsar\Extension\Forum\Service\BanServiceInterface;
use Pulsar\Extension\Forum\Service\ForumSearchServiceInterface;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Service\LeaderboardServiceInterface;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Extension\Forum\Service\PrivilegeCheckerInterface;
use Pulsar\Extension\Forum\Service\ReputationServiceInterface;
use Pulsar\Extension\Forum\Service\TagServiceInterface;
use Pulsar\Extension\Forum\Service\VoteServiceInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Tag\TagRepositoryInterface;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Extension\Forum\Vote\PostVoteRepositoryInterface;
use Pulsar\Extension\Forum\Vote\ThreadVoteRepositoryInterface;
use Pulsar\Security\AntiSpam\AntiSpamPipelineInterface;

/**
 * Binds forum core services: reputation, badges, voting, moderation, forum
 * service, tag service, content rendering, anti-abuse, and notifications.
 */
#[Internal(reason: 'Forum service wiring; use interfaces for public API')]
final readonly class ForumCoreServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        if (!$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ForumConfig $config */
        $config = $container->has(ForumConfig::class)
            ? $container->get(ForumConfig::class)
            : new ForumConfig();

        /** @var EventDispatcherInterface $events */
        $events = $container->get(EventDispatcherInterface::class);

        /** @var LoggerInterface $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        // Content rendering stack
        $markdownRenderer = new MarkdownRenderer();
        $container->instance(MarkdownRenderer::class, $markdownRenderer);
        $container->instance(MarkdownRendererInterface::class, $markdownRenderer);

        $bodyPolicy = new ForumBodyPolicy();
        $container->instance(ForumBodyPolicy::class, $bodyPolicy);

        // Anti-abuse middleware (delegates to the shared anti-spam pipeline)
        /** @var AntiSpamPipelineInterface $antiSpamPipeline */
        $antiSpamPipeline = $container->get(AntiSpamPipelineInterface::class);
        $container->instance(
            ForumAntiAbuseMiddleware::class,
            new ForumAntiAbuseMiddleware($antiSpamPipeline),
        );

        // Reputation service
        /** @var ForumProfileRepositoryInterface $profiles */
        $profiles = $container->get(ForumProfileRepositoryInterface::class);

        $reputationService = new ReputationService($profiles, $events);
        $container->instance(ReputationServiceInterface::class, $reputationService);

        // Badge service
        /** @var UserBadgeRepositoryInterface $userBadges */
        $userBadges = $container->get(UserBadgeRepositoryInterface::class);

        /** @var PostRepositoryInterface $posts */
        $posts = $container->get(PostRepositoryInterface::class);

        /** @var ThreadRepositoryInterface $threads */
        $threads = $container->get(ThreadRepositoryInterface::class);

        $badgeService = new BadgeService(
            $userBadges,
            $profiles,
            $posts,
            $threads,
            $events,
            $config,
        );
        $container->instance(BadgeServiceInterface::class, $badgeService);

        // Vote service
        /** @var ThreadVoteRepositoryInterface $threadVotes */
        $threadVotes = $container->get(ThreadVoteRepositoryInterface::class);

        /** @var PostVoteRepositoryInterface $postVotes */
        $postVotes = $container->get(PostVoteRepositoryInterface::class);

        $voteService = new VoteService(
            $threadVotes,
            $postVotes,
            $threads,
            $posts,
            $profiles,
            $reputationService,
            $badgeService,
            $events,
            $config,
        );
        $container->instance(VoteServiceInterface::class, $voteService);

        // Moderation service
        /** @var ThreadReportRepositoryInterface $threadReports */
        $threadReports = $container->get(ThreadReportRepositoryInterface::class);

        /** @var PostReportRepositoryInterface $postReports */
        $postReports = $container->get(PostReportRepositoryInterface::class);

        $moderationService = new ModerationService(
            $threadReports,
            $postReports,
            $profiles,
            $badgeService,
            $events,
        );
        $container->instance(ModerationServiceInterface::class, $moderationService);

        // Tag service
        /** @var TagRepositoryInterface $tags */
        $tags = $container->get(TagRepositoryInterface::class);

        $container->instance(
            TagServiceInterface::class,
            new TagService($tags),
        );

        // Core forum service
        /** @var CategoryRepositoryInterface|null $categories */
        $categories = $container->has(CategoryRepositoryInterface::class)
            ? $container->get(CategoryRepositoryInterface::class)
            : null;

        $forumService = new ForumService(
            $threads,
            $posts,
            $profiles,
            $reputationService,
            $badgeService,
            $events,
            $config,
            $categories,
        );
        $container->instance(ForumServiceInterface::class, $forumService);

        // Notification dispatcher
        /** @var ThreadSubscriptionRepositoryInterface $subscriptions */
        $subscriptions = $container->get(ThreadSubscriptionRepositoryInterface::class);

        $container->instance(
            ForumNotificationDispatcher::class,
            new ForumNotificationDispatcher(
                $threads,
                $subscriptions,
                $posts,
                $logger,
            ),
        );

        // Privilege checker
        $privilegeChecker = new PrivilegeChecker();
        $container->instance(PrivilegeCheckerInterface::class, $privilegeChecker);

        // Ban service
        /** @var UserBanRepositoryInterface $userBans */
        $userBans = $container->get(UserBanRepositoryInterface::class);

        /** @var ForumModerationLogRepositoryInterface $moderationLogs */
        $moderationLogs = $container->get(ForumModerationLogRepositoryInterface::class);

        $banService = new BanService($userBans, $profiles, $moderationLogs, $events);
        $container->instance(BanServiceInterface::class, $banService);

        // Auto-moderation service
        $container->instance(
            AutoModerationService::class,
            new AutoModerationService($posts, $threadReports, $postReports),
        );

        // Badge evaluator
        $container->instance(
            BadgeEvaluator::class,
            new BadgeEvaluator(
                $badgeService,
                $posts,
                $threads,
                $profiles,
                $config->badges,
            ),
        );

        // Forum search service
        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        $container->instance(
            ForumSearchServiceInterface::class,
            new ForumSearchService($connection),
        );

        // Leaderboard service
        $container->instance(
            LeaderboardServiceInterface::class,
            new LeaderboardService($profiles, $connection),
        );
    }
}
