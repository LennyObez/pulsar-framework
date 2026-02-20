<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum;

use Override;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\PostBootExtensionInterface;
use Pulsar\Extensibility\PreBootExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Admin\Gateway\AdminGateway;
use Pulsar\Extension\Cms\Dashboard\DashboardWidgetInterface;
use Pulsar\Extension\Forum\Admin\ForumCategoryResource;
use Pulsar\Extension\Forum\Admin\ForumDashboardWidget;
use Pulsar\Extension\Forum\Admin\ForumPostResource;
use Pulsar\Extension\Forum\Admin\ForumProfileResource;
use Pulsar\Extension\Forum\Admin\ForumReportResource;
use Pulsar\Extension\Forum\Admin\ForumTagResource;
use Pulsar\Extension\Forum\Admin\ForumThreadResource;
use Pulsar\Extension\Forum\Cms\ForumCmsDashboardWidget;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Event\PostAcceptedAsSolution;
use Pulsar\Extension\Forum\Event\PostCreated;
use Pulsar\Extension\Forum\Event\ReportSubmitted;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Http\Controller\Admin\BadgeController;
use Pulsar\Extension\Forum\Http\Controller\Admin\CategoryController as AdminCategoryController;
use Pulsar\Extension\Forum\Http\Controller\Admin\DashboardController as AdminDashboardController;
use Pulsar\Extension\Forum\Http\Controller\Admin\ModerationController as AdminModerationController;
use Pulsar\Extension\Forum\Http\Controller\Admin\PostController as AdminPostController;
use Pulsar\Extension\Forum\Http\Controller\Admin\SettingsController as AdminSettingsController;
use Pulsar\Extension\Forum\Http\Controller\Admin\TagController as AdminTagController;
use Pulsar\Extension\Forum\Http\Controller\Admin\ThreadController as AdminThreadController;
use Pulsar\Extension\Forum\Http\Controller\Admin\UserController as AdminUserController;
use Pulsar\Extension\Forum\Http\Controller\Api\CategoryApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ModerationApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\PostApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ProfileApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ReportApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\SearchApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\TagApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ThreadApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\VoteApiController;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\Routing\RouterInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Forum extension for community discussion, Q&A, and knowledge sharing.
 *
 * Provides threaded discussions, voting, reputation, badges, moderation,
 * tagging, subscriptions, and anti-abuse protection. Designed for
 * regulated, mission-critical domains with multi-tenancy support.
 */
#[Api(since: '1.0.0')]
final readonly class ForumExtension implements ExtensionInterface, PreBootExtensionInterface, PostBootExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/forum';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider (ForumServiceProvider) handles all bindings:
        // - Repository bindings (raw-DB-backed implementations)
        // - Core service bindings (reputation, badges, voting, moderation, etc.)
        // - Forum permissions registration with RoleRegistryInterface
        // The provider is listed in providers() and invoked by the framework.
    }

    #[Override]
    public function preBoot(ContainerInterface $container): void
    {
        // Load forum config from file unless already pre-registered
        if (!$container->has(ForumConfig::class) && $container->has(ConfigManagerInterface::class)) {
            /** @var ConfigManagerInterface $configManager */
            $configManager = $container->get(ConfigManagerInterface::class);
            $configPath = $configManager->configPath();

            if ($configPath !== null && is_file($configPath . DIRECTORY_SEPARATOR . 'forum.php')) {
                /** @psalm-suppress UnresolvableInclude */
                $forumData = require $configPath . DIRECTORY_SEPARATOR . 'forum.php';

                if (is_array($forumData)) {
                    /** @var array<string, mixed> $forumData */
                    $forumConfig = ForumConfig::fromArray($forumData);
                    $container->instance(ForumConfig::class, $forumConfig);
                }
            }
        }

        if (!$container->has(ForumConfig::class)) {
            $container->instance(ForumConfig::class, ForumConfig::fromArray([]));
        }
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerApiRoutes($router);
        $this->registerAdminRoutes($router);
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        $this->registerNotificationListeners($container);
        $this->registerAdminResources($container);
        $this->registerCmsWidgets($container);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            ForumServiceProvider::class,
        ];
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/forum';

        // Categories
        $router->get("{$prefix}/categories", [CategoryApiController::class, 'index'], 'forum.api.categories.index');
        $router->get("{$prefix}/categories/{id}", [CategoryApiController::class, 'show'], 'forum.api.categories.show');

        // Threads
        $router->get("{$prefix}/threads", [ThreadApiController::class, 'index'], 'forum.api.threads.index');
        $router->post("{$prefix}/threads", [ThreadApiController::class, 'create'], 'forum.api.threads.create');
        $router->get("{$prefix}/threads/{id}", [ThreadApiController::class, 'show'], 'forum.api.threads.show');
        $router->put("{$prefix}/threads/{id}", [ThreadApiController::class, 'update'], 'forum.api.threads.update');
        $router->delete("{$prefix}/threads/{id}", [ThreadApiController::class, 'delete'], 'forum.api.threads.delete');

        // Posts
        $router->get("{$prefix}/threads/{threadId}/posts", [PostApiController::class, 'index'], 'forum.api.posts.index');
        $router->post("{$prefix}/threads/{threadId}/posts", [PostApiController::class, 'create'], 'forum.api.posts.create');
        $router->put("{$prefix}/posts/{id}", [PostApiController::class, 'update'], 'forum.api.posts.update');
        $router->delete("{$prefix}/posts/{id}", [PostApiController::class, 'delete'], 'forum.api.posts.delete');
        $router->post("{$prefix}/posts/{id}/solution", [PostApiController::class, 'markSolution'], 'forum.api.posts.mark_solution');

        // Votes
        $router->post("{$prefix}/threads/{id}/vote", [VoteApiController::class, 'voteThread'], 'forum.api.threads.vote');
        $router->delete("{$prefix}/threads/{id}/vote", [VoteApiController::class, 'removeThreadVote'], 'forum.api.threads.vote.remove');
        $router->post("{$prefix}/posts/{id}/vote", [VoteApiController::class, 'votePost'], 'forum.api.posts.vote');
        $router->delete("{$prefix}/posts/{id}/vote", [VoteApiController::class, 'removePostVote'], 'forum.api.posts.vote.remove');

        // Tags
        $router->get("{$prefix}/tags", [TagApiController::class, 'index'], 'forum.api.tags.index');
        $router->get("{$prefix}/tags/{slug}", [TagApiController::class, 'show'], 'forum.api.tags.show');

        // Reports
        $router->post("{$prefix}/threads/{id}/report", [ReportApiController::class, 'reportThread'], 'forum.api.threads.report');
        $router->post("{$prefix}/posts/{id}/report", [ReportApiController::class, 'reportPost'], 'forum.api.posts.report');

        // Profiles
        $router->get("{$prefix}/profiles/{userId}", [ProfileApiController::class, 'show'], 'forum.api.profiles.show');

        // Search
        $router->get("{$prefix}/search", [SearchApiController::class, 'search'], 'forum.api.search');

        // Moderation API
        $router->get("{$prefix}/moderation/reports", [ModerationApiController::class, 'pendingReports'], 'forum.api.moderation.reports');
        $router->post("{$prefix}/moderation/reports/{id}/resolve", [ModerationApiController::class, 'resolveReport'], 'forum.api.moderation.reports.resolve');
    }

    /**
     * Register admin panel routes under /admin/forum.
     *
     * CSRF protection for admin write routes (POST/PUT/DELETE) is handled by
     * the framework's admin middleware stack, which applies CSRF validation
     * to all state-changing requests under the /admin prefix automatically.
     */
    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/forum';

        // Dashboard
        $router->get($prefix, [AdminDashboardController::class, 'index'], 'forum.admin.dashboard');

        // Categories
        $router->get("{$prefix}/categories", [AdminCategoryController::class, 'index'], 'forum.admin.categories.index');
        $router->post("{$prefix}/categories", [AdminCategoryController::class, 'create'], 'forum.admin.categories.create');
        $router->get("{$prefix}/categories/{id}", [AdminCategoryController::class, 'show'], 'forum.admin.categories.show');
        $router->put("{$prefix}/categories/{id}", [AdminCategoryController::class, 'update'], 'forum.admin.categories.update');
        $router->delete("{$prefix}/categories/{id}", [AdminCategoryController::class, 'delete'], 'forum.admin.categories.delete');

        // Threads
        $router->get("{$prefix}/threads", [AdminThreadController::class, 'index'], 'forum.admin.threads.index');
        $router->get("{$prefix}/threads/{id}", [AdminThreadController::class, 'show'], 'forum.admin.threads.show');
        $router->put("{$prefix}/threads/{id}", [AdminThreadController::class, 'update'], 'forum.admin.threads.update');
        $router->delete("{$prefix}/threads/{id}", [AdminThreadController::class, 'delete'], 'forum.admin.threads.delete');
        $router->post("{$prefix}/threads/{id}/lock", [AdminThreadController::class, 'lock'], 'forum.admin.threads.lock');
        $router->post("{$prefix}/threads/{id}/unlock", [AdminThreadController::class, 'unlock'], 'forum.admin.threads.unlock');
        $router->post("{$prefix}/threads/{id}/pin", [AdminThreadController::class, 'pin'], 'forum.admin.threads.pin');
        $router->post("{$prefix}/threads/{id}/unpin", [AdminThreadController::class, 'unpin'], 'forum.admin.threads.unpin');

        // Posts
        $router->get("{$prefix}/posts", [AdminPostController::class, 'index'], 'forum.admin.posts.index');
        $router->get("{$prefix}/posts/{id}", [AdminPostController::class, 'show'], 'forum.admin.posts.show');
        $router->delete("{$prefix}/posts/{id}", [AdminPostController::class, 'delete'], 'forum.admin.posts.delete');

        // Moderation (reports)
        $router->get("{$prefix}/moderation", [AdminModerationController::class, 'index'], 'forum.admin.moderation.index');
        $router->get("{$prefix}/moderation/reports/{id}", [AdminModerationController::class, 'show'], 'forum.admin.moderation.show');
        $router->post("{$prefix}/moderation/reports/{id}/resolve", [AdminModerationController::class, 'resolve'], 'forum.admin.moderation.resolve');
        $router->post("{$prefix}/moderation/users/{id}/ban", [AdminModerationController::class, 'banUser'], 'forum.admin.moderation.ban');
        $router->post("{$prefix}/moderation/users/{id}/unban", [AdminModerationController::class, 'unbanUser'], 'forum.admin.moderation.unban');

        // Tags
        $router->get("{$prefix}/tags", [AdminTagController::class, 'index'], 'forum.admin.tags.index');
        $router->post("{$prefix}/tags", [AdminTagController::class, 'create'], 'forum.admin.tags.create');
        $router->put("{$prefix}/tags/{id}", [AdminTagController::class, 'update'], 'forum.admin.tags.update');
        $router->delete("{$prefix}/tags/{id}", [AdminTagController::class, 'delete'], 'forum.admin.tags.delete');

        // Users
        $router->get("{$prefix}/users", [AdminUserController::class, 'index'], 'forum.admin.users.index');
        $router->get("{$prefix}/users/{id}", [AdminUserController::class, 'show'], 'forum.admin.users.show');

        // Badges
        $router->get("{$prefix}/badges", [BadgeController::class, 'index'], 'forum.admin.badges.index');
        $router->post("{$prefix}/badges/{userId}/award", [BadgeController::class, 'award'], 'forum.admin.badges.award');
        $router->delete("{$prefix}/badges/{userId}/{badge}", [BadgeController::class, 'revoke'], 'forum.admin.badges.revoke');

        // Settings
        $router->get("{$prefix}/settings", [AdminSettingsController::class, 'show'], 'forum.admin.settings.show');
        $router->put("{$prefix}/settings", [AdminSettingsController::class, 'update'], 'forum.admin.settings.update');
    }

    private function registerAdminResources(ContainerInterface $container): void
    {
        if (!$container->has(AdminGateway::class)) {
            return;
        }

        /** @var AdminGateway $gateway */
        $gateway = $container->get(AdminGateway::class);

        $gateway->registerResource(new ForumThreadResource());
        $gateway->registerResource(new ForumPostResource());
        $gateway->registerResource(new ForumCategoryResource());
        $gateway->registerResource(new ForumTagResource());
        $gateway->registerResource(new ForumProfileResource());
        $gateway->registerResource(new ForumReportResource());

        if ($container->has(ConnectionInterface::class)) {
            /** @var ConnectionInterface $connection */
            $connection = $container->get(ConnectionInterface::class);
            $container->instance(ForumDashboardWidget::class, new ForumDashboardWidget($connection));
        }
    }

    private function registerCmsWidgets(ContainerInterface $container): void
    {
        if (!$container->has(DashboardWidgetInterface::class) || !$container->has(ConnectionInterface::class)) {
            return;
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);
        $container->instance(ForumCmsDashboardWidget::class, new ForumCmsDashboardWidget($connection));
    }

    private function registerNotificationListeners(ContainerInterface $container): void
    {
        if (
            !$container->has(ForumNotificationDispatcher::class)
            || !$container->has(ListenerProviderInterface::class)
        ) {
            return;
        }

        /** @var ForumNotificationDispatcher $notificationDispatcher */
        $notificationDispatcher = $container->get(ForumNotificationDispatcher::class);

        /** @var ListenerProviderInterface $listenerProvider */
        $listenerProvider = $container->get(ListenerProviderInterface::class);

        $listenerProvider->addListener(
            PostCreated::class,
            $notificationDispatcher->onPostCreated(...),
            moduleId: 'pulsar/forum',
        );

        $listenerProvider->addListener(
            PostAcceptedAsSolution::class,
            $notificationDispatcher->onPostAcceptedAsSolution(...),
            moduleId: 'pulsar/forum',
        );

        $listenerProvider->addListener(
            VoteCast::class,
            $notificationDispatcher->onVoteCast(...),
            moduleId: 'pulsar/forum',
        );

        $listenerProvider->addListener(
            ReportSubmitted::class,
            $notificationDispatcher->onReportSubmitted(...),
            moduleId: 'pulsar/forum',
        );
    }
}
