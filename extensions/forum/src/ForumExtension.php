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
use Pulsar\Extension\Forum\Event\ReputationChanged;
use Pulsar\Extension\Forum\Event\VoteCast;
use Pulsar\Extension\Forum\Http\Controller\Account\AccountController;
use Pulsar\Extension\Forum\Http\Controller\Admin\BadgeController;
use Pulsar\Extension\Forum\Http\Controller\Admin\BanController as AdminBanController;
use Pulsar\Extension\Forum\Http\Controller\Admin\CategoryController as AdminCategoryController;
use Pulsar\Extension\Forum\Http\Controller\Admin\DashboardController as AdminDashboardController;
use Pulsar\Extension\Forum\Http\Controller\Admin\LeaderboardController as AdminLeaderboardController;
use Pulsar\Extension\Forum\Http\Controller\Admin\ModerationController as AdminModerationController;
use Pulsar\Extension\Forum\Http\Controller\Admin\ModerationLogController as AdminModerationLogController;
use Pulsar\Extension\Forum\Http\Controller\Admin\NotificationPreferencesController as AdminNotificationPreferencesController;
use Pulsar\Extension\Forum\Http\Controller\Admin\PostController as AdminPostController;
use Pulsar\Extension\Forum\Http\Controller\Admin\SettingsController as AdminSettingsController;
use Pulsar\Extension\Forum\Http\Controller\Admin\TagController as AdminTagController;
use Pulsar\Extension\Forum\Http\Controller\Admin\ThreadController as AdminThreadController;
use Pulsar\Extension\Forum\Http\Controller\Admin\UserController as AdminUserController;
use Pulsar\Extension\Forum\Http\Controller\Api\CategoryApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ForumSearchController;
use Pulsar\Extension\Forum\Http\Controller\Api\LeaderboardController as ApiLeaderboardController;
use Pulsar\Extension\Forum\Http\Controller\Api\ModerationApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\NotificationApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\PostApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ProfileApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\PublicProfileController;
use Pulsar\Extension\Forum\Http\Controller\Api\ReportApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\SearchApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\TagApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\ThreadApiController;
use Pulsar\Extension\Forum\Http\Controller\Api\VoteApiController;
use Pulsar\Extension\Forum\Http\Controller\Auth\LoginController;
use Pulsar\Extension\Forum\Http\Controller\Auth\PasswordResetController;
use Pulsar\Extension\Forum\Http\Controller\Auth\RegisterController;
use Pulsar\Extension\Forum\Http\Controller\Page\CategoryPageController;
use Pulsar\Extension\Forum\Http\Controller\Page\HomeController;
use Pulsar\Extension\Forum\Http\Controller\Page\SearchPageController;
use Pulsar\Extension\Forum\Http\Controller\Page\TagPageController;
use Pulsar\Extension\Forum\Http\Controller\Page\ThreadPageController;
use Pulsar\Extension\Forum\Http\Controller\Page\UserProfilePageController;
use Pulsar\Extension\Forum\ImportExport\ForumImportExportProvider;
use Pulsar\Extension\Forum\Internal\Notification\BadgeEvaluator;
use Pulsar\Extension\Forum\Internal\Notification\ForumNotificationDispatcher;
use Pulsar\ImportExport\ImportExportRegistry;
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
        $this->registerPageRoutes($router);
        $this->registerAuthRoutes($router);
        $this->registerAccountRoutes($router);
        $this->registerApiRoutes($router);
        $this->registerAdminRoutes($router);
    }

    #[Override]
    public function postBoot(ContainerInterface $container): void
    {
        $this->registerNotificationListeners($container);
        $this->registerAdminResources($container);
        $this->registerCmsWidgets($container);
        $this->registerAccountSections($container);
        $this->registerImportExportProvider($container);
    }

    /**
     * Register forum sections in the CMS account view (when CMS is active).
     */
    private function registerAccountSections(ContainerInterface $container): void
    {
        if (!$container->has(\Pulsar\Extension\Cms\Account\AccountSectionRegistry::class)) {
            return;
        }

        /** @var \Pulsar\Extension\Cms\Account\AccountSectionRegistry $registry */
        $registry = $container->get(\Pulsar\Extension\Cms\Account\AccountSectionRegistry::class);

        $registry->register(new Account\ForumAccountSectionProvider(
            $container->get(Thread\ThreadRepositoryInterface::class),
            $container->get(Post\PostRepositoryInterface::class),
            $container->get(Badge\BadgeServiceInterface::class),
            $container->get(Profile\ForumProfileRepositoryInterface::class),
        ));
    }

    private function registerImportExportProvider(ContainerInterface $container): void
    {
        if (!$container->has(ImportExportRegistry::class)) {
            return;
        }

        if (
            !$container->has(Category\CategoryRepositoryInterface::class)
            || !$container->has(Category\CategoryTranslationRepositoryInterface::class)
            || !$container->has(Thread\ThreadRepositoryInterface::class)
            || !$container->has(Tag\TagRepositoryInterface::class)
        ) {
            return;
        }

        /** @var ImportExportRegistry $registry */
        $registry = $container->get(ImportExportRegistry::class);

        $registry->register(new ForumImportExportProvider(
            $container->get(Category\CategoryRepositoryInterface::class),
            $container->get(Category\CategoryTranslationRepositoryInterface::class),
            $container->get(Thread\ThreadRepositoryInterface::class),
            $container->get(Tag\TagRepositoryInterface::class),
        ));
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

    /**
     * Register public-facing page routes under /community.
     *
     * These routes serve server-rendered HTML pages for categories, threads,
     * posts, user profiles, tags, and search. All page routes are prefixed
     * with /community to avoid colliding with the CMS catch-all route.
     */
    private function registerPageRoutes(RouterInterface $router): void
    {
        $prefix = '/community';

        // Homepage
        $router->get($prefix, [HomeController::class, 'index'], 'forum.page.home');

        // Category pages
        $router->get("$prefix/c/{slug}", [CategoryPageController::class, 'show'], 'forum.page.category');

        // Thread pages
        $router->get("$prefix/t/{slug}", [ThreadPageController::class, 'show'], 'forum.page.thread');

        // User profiles
        $router->get("$prefix/u/{userId}", [UserProfilePageController::class, 'show'], 'forum.page.user_profile');

        // Tags
        $router->get("$prefix/tags", [TagPageController::class, 'index'], 'forum.page.tags');

        // Search
        $router->get("$prefix/search", [SearchPageController::class, 'index'], 'forum.page.search');
    }

    /**
     * Register authentication routes (registration, login, password reset).
     *
     * All routes are prefixed with /community to avoid colliding with the
     * CMS or other extensions.
     */
    private function registerAuthRoutes(RouterInterface $router): void
    {
        $prefix = '/community';

        $router->get("$prefix/register", [RegisterController::class, 'showForm'], 'forum.auth.register');
        $router->post("$prefix/register", [RegisterController::class, 'register'], 'forum.auth.register.submit');

        $router->get("$prefix/login", [LoginController::class, 'showForm'], 'forum.auth.login');
        $router->post("$prefix/login", [LoginController::class, 'login'], 'forum.auth.login.submit');
        $router->post("$prefix/logout", [LoginController::class, 'logout'], 'forum.auth.logout');

        $router->get("$prefix/forgot-password", [PasswordResetController::class, 'showRequestForm'], 'forum.auth.forgot_password');
        $router->post("$prefix/forgot-password", [PasswordResetController::class, 'sendResetLink'], 'forum.auth.forgot_password.submit');
        $router->get("$prefix/reset-password", [PasswordResetController::class, 'showResetForm'], 'forum.auth.reset_password');
        $router->post("$prefix/reset-password", [PasswordResetController::class, 'resetPassword'], 'forum.auth.reset_password.submit');
    }

    /**
     * Register authenticated user account routes.
     *
     * Prefixed with /community to avoid collisions with other extensions.
     */
    private function registerAccountRoutes(RouterInterface $router): void
    {
        $prefix = '/community';

        $router->get("$prefix/account", [AccountController::class, 'profile'], 'forum.account.profile');
        $router->get("$prefix/account/threads", [AccountController::class, 'threads'], 'forum.account.threads');
        $router->get("$prefix/account/posts", [AccountController::class, 'posts'], 'forum.account.posts');
        $router->get("$prefix/account/settings", [AccountController::class, 'settings'], 'forum.account.settings');
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/forum';

        // Categories
        $router->get("$prefix/categories", [CategoryApiController::class, 'index'], 'forum.api.categories.index');
        $router->get("$prefix/categories/{id}", [CategoryApiController::class, 'show'], 'forum.api.categories.show');

        // Threads
        $router->get("$prefix/threads", [ThreadApiController::class, 'index'], 'forum.api.threads.index');
        $router->post("$prefix/threads", [ThreadApiController::class, 'create'], 'forum.api.threads.create');
        $router->get("$prefix/threads/{id}", [ThreadApiController::class, 'show'], 'forum.api.threads.show');
        $router->put("$prefix/threads/{id}", [ThreadApiController::class, 'update'], 'forum.api.threads.update');
        $router->delete("$prefix/threads/{id}", [ThreadApiController::class, 'delete'], 'forum.api.threads.delete');

        // Posts
        $router->get("$prefix/threads/{threadId}/posts", [PostApiController::class, 'index'], 'forum.api.posts.index');
        $router->post("$prefix/threads/{threadId}/posts", [PostApiController::class, 'create'], 'forum.api.posts.create');
        $router->put("$prefix/posts/{id}", [PostApiController::class, 'update'], 'forum.api.posts.update');
        $router->delete("$prefix/posts/{id}", [PostApiController::class, 'delete'], 'forum.api.posts.delete');
        $router->post("$prefix/posts/{id}/solution", [PostApiController::class, 'accept'], 'forum.api.posts.mark_solution');

        // Votes
        $router->post("$prefix/threads/{id}/vote", [VoteApiController::class, 'threadVote'], 'forum.api.threads.vote');
        $router->delete("$prefix/threads/{id}/vote", [VoteApiController::class, 'removeThreadVote'], 'forum.api.threads.vote.remove');
        $router->post("$prefix/posts/{id}/vote", [VoteApiController::class, 'postVote'], 'forum.api.posts.vote');
        $router->delete("$prefix/posts/{id}/vote", [VoteApiController::class, 'removePostVote'], 'forum.api.posts.vote.remove');

        // Tags
        $router->get("$prefix/tags", [TagApiController::class, 'index'], 'forum.api.tags.index');
        $router->get("$prefix/tags/{slug}", [TagApiController::class, 'show'], 'forum.api.tags.show');

        // Reports
        $router->post("$prefix/threads/{id}/report", [ReportApiController::class, 'reportThread'], 'forum.api.threads.report');
        $router->post("$prefix/posts/{id}/report", [ReportApiController::class, 'reportPost'], 'forum.api.posts.report');

        // Profiles
        $router->get("$prefix/profiles/{userId}", [ProfileApiController::class, 'show'], 'forum.api.profiles.show');

        // Search
        $router->get("$prefix/search", [SearchApiController::class, 'search'], 'forum.api.search');

        // Moderation API
        $router->get("$prefix/moderation/reports", [ModerationApiController::class, 'pendingReports'], 'forum.api.moderation.reports');
        $router->post("$prefix/moderation/reports/{id}/resolve", [ModerationApiController::class, 'resolveReport'], 'forum.api.moderation.reports.resolve');

        // Notifications API
        $router->get("$prefix/notifications", [NotificationApiController::class, 'index'], 'forum.api.notifications.index');
        $router->patch("$prefix/notifications/{id}/read", [NotificationApiController::class, 'markRead'], 'forum.api.notifications.mark_read');
        $router->post("$prefix/notifications/read-all", [NotificationApiController::class, 'markAllRead'], 'forum.api.notifications.read_all');
        $router->get("$prefix/notifications/unread-count", [NotificationApiController::class, 'unreadCount'], 'forum.api.notifications.unread_count');

        // Full-text search
        $router->get("$prefix/search/full", [ForumSearchController::class, 'search'], 'forum.api.search.full');

        // Leaderboard (public API)
        $router->get("$prefix/leaderboard", [ApiLeaderboardController::class, 'index'], 'forum.api.leaderboard');

        // Public profiles
        $router->get("$prefix/users/{userId}/profile", [PublicProfileController::class, 'show'], 'forum.api.users.profile');
        $router->get("$prefix/users/{userId}/activity", [PublicProfileController::class, 'activity'], 'forum.api.users.activity');
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
        $router->get("$prefix/categories", [AdminCategoryController::class, 'index'], 'forum.admin.categories.index');
        $router->post("$prefix/categories", [AdminCategoryController::class, 'create'], 'forum.admin.categories.create');
        $router->get("$prefix/categories/{id}", [AdminCategoryController::class, 'show'], 'forum.admin.categories.show');
        $router->put("$prefix/categories/{id}", [AdminCategoryController::class, 'update'], 'forum.admin.categories.update');
        $router->delete("$prefix/categories/{id}", [AdminCategoryController::class, 'delete'], 'forum.admin.categories.delete');

        // Threads
        $router->get("$prefix/threads", [AdminThreadController::class, 'index'], 'forum.admin.threads.index');
        $router->get("$prefix/threads/{id}", [AdminThreadController::class, 'show'], 'forum.admin.threads.show');
        $router->put("$prefix/threads/{id}", [AdminThreadController::class, 'update'], 'forum.admin.threads.update');
        $router->delete("$prefix/threads/{id}", [AdminThreadController::class, 'delete'], 'forum.admin.threads.delete');
        $router->post("$prefix/threads/{id}/lock", [AdminThreadController::class, 'lock'], 'forum.admin.threads.lock');
        $router->post("$prefix/threads/{id}/unlock", [AdminThreadController::class, 'unlock'], 'forum.admin.threads.unlock');
        $router->post("$prefix/threads/{id}/pin", [AdminThreadController::class, 'pin'], 'forum.admin.threads.pin');
        $router->post("$prefix/threads/{id}/unpin", [AdminThreadController::class, 'unpin'], 'forum.admin.threads.unpin');

        // Posts
        $router->get("$prefix/posts", [AdminPostController::class, 'index'], 'forum.admin.posts.index');
        $router->get("$prefix/posts/{id}", [AdminPostController::class, 'show'], 'forum.admin.posts.show');
        $router->delete("$prefix/posts/{id}", [AdminPostController::class, 'delete'], 'forum.admin.posts.delete');

        // Moderation (reports)
        $router->get("$prefix/moderation", [AdminModerationController::class, 'index'], 'forum.admin.moderation.index');
        $router->get("$prefix/moderation/reports/{id}", [AdminModerationController::class, 'show'], 'forum.admin.moderation.show');
        $router->post("$prefix/moderation/reports/{id}/resolve", [AdminModerationController::class, 'resolve'], 'forum.admin.moderation.resolve');
        $router->post("$prefix/moderation/users/{id}/ban", [AdminModerationController::class, 'banUser'], 'forum.admin.moderation.ban');
        $router->post("$prefix/moderation/users/{id}/unban", [AdminModerationController::class, 'unbanUser'], 'forum.admin.moderation.unban');

        // Tags
        $router->get("$prefix/tags", [AdminTagController::class, 'index'], 'forum.admin.tags.index');
        $router->post("$prefix/tags", [AdminTagController::class, 'create'], 'forum.admin.tags.create');
        $router->put("$prefix/tags/{id}", [AdminTagController::class, 'update'], 'forum.admin.tags.update');
        $router->delete("$prefix/tags/{id}", [AdminTagController::class, 'delete'], 'forum.admin.tags.delete');

        // Users
        $router->get("$prefix/users", [AdminUserController::class, 'index'], 'forum.admin.users.index');
        $router->get("$prefix/users/{id}", [AdminUserController::class, 'show'], 'forum.admin.users.show');

        // Badges
        $router->get("$prefix/badges", [BadgeController::class, 'index'], 'forum.admin.badges.index');
        $router->post("$prefix/badges/{userId}/award", [BadgeController::class, 'award'], 'forum.admin.badges.award');
        $router->delete("$prefix/badges/{userId}/{badge}", [BadgeController::class, 'revoke'], 'forum.admin.badges.revoke');

        // Settings
        $router->get("$prefix/settings", [AdminSettingsController::class, 'show'], 'forum.admin.settings.show');
        $router->put("$prefix/settings", [AdminSettingsController::class, 'update'], 'forum.admin.settings.update');

        // Bans
        $router->get("$prefix/bans", [AdminBanController::class, 'index'], 'forum.admin.bans.index');
        $router->get("$prefix/bans/{id}", [AdminBanController::class, 'show'], 'forum.admin.bans.show');
        $router->post("$prefix/bans", [AdminBanController::class, 'create'], 'forum.admin.bans.create');
        $router->post("$prefix/bans/{id}/revoke", [AdminBanController::class, 'revoke'], 'forum.admin.bans.revoke');

        // Moderation log
        $router->get("$prefix/moderation-log", [AdminModerationLogController::class, 'index'], 'forum.admin.moderation_log.index');

        // Leaderboard
        $router->get("$prefix/leaderboard", [AdminLeaderboardController::class, 'index'], 'forum.admin.leaderboard.index');

        // Notification preferences
        $router->get("$prefix/notification-preferences", [AdminNotificationPreferencesController::class, 'show'], 'forum.admin.notification_preferences.show');
        $router->put("$prefix/notification-preferences", [AdminNotificationPreferencesController::class, 'update'], 'forum.admin.notification_preferences.update');
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
            [$notificationDispatcher, 'onPostCreated'],
            moduleId: 'pulsar/forum',
        );

        $listenerProvider->addListener(
            PostAcceptedAsSolution::class,
            [$notificationDispatcher, 'onPostAcceptedAsSolution'],
            moduleId: 'pulsar/forum',
        );

        $listenerProvider->addListener(
            VoteCast::class,
            [$notificationDispatcher, 'onVoteCast'],
            moduleId: 'pulsar/forum',
        );

        $listenerProvider->addListener(
            ReportSubmitted::class,
            [$notificationDispatcher, 'onReportSubmitted'],
            moduleId: 'pulsar/forum',
        );

        // Badge evaluator listeners
        if ($container->has(BadgeEvaluator::class)) {
            /** @var BadgeEvaluator $badgeEvaluator */
            $badgeEvaluator = $container->get(BadgeEvaluator::class);

            $listenerProvider->addListener(
                PostCreated::class,
                [$badgeEvaluator, 'handleEvent'],
                moduleId: 'pulsar/forum',
            );

            $listenerProvider->addListener(
                VoteCast::class,
                [$badgeEvaluator, 'handleEvent'],
                moduleId: 'pulsar/forum',
            );

            $listenerProvider->addListener(
                PostAcceptedAsSolution::class,
                [$badgeEvaluator, 'handleEvent'],
                moduleId: 'pulsar/forum',
            );

            $listenerProvider->addListener(
                ReputationChanged::class,
                [$badgeEvaluator, 'handleEvent'],
                moduleId: 'pulsar/forum',
            );
        }
    }
}
