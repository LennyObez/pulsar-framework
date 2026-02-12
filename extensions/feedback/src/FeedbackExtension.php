<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Feedback\Http\Controller\Admin\FeedbackController as AdminFeedbackController;
use Pulsar\Extension\Feedback\Http\Controller\Api\FeedbackApiController;
use Pulsar\Routing\RouterInterface;

/**
 * Feedback extension for user feedback collection with admin triage.
 *
 * Provides a public API for authenticated users to submit and view their
 * feedback, and an admin panel for triaging, responding, and linking
 * feedback to GitHub issues.
 */
#[Api(since: '1.0.0')]
final readonly class FeedbackExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/feedback';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider (FeedbackServiceProvider) handles all bindings.
        // The provider is listed in providers() and invoked by the framework.
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        $this->registerApiRoutes($router);
        $this->registerAdminRoutes($router);
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            FeedbackServiceProvider::class,
        ];
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $prefix = '/api/v1/feedback';

        $router->post($prefix, [FeedbackApiController::class, 'submit'], 'feedback.api.submit');
        $router->get($prefix, [FeedbackApiController::class, 'index'], 'feedback.api.index');
        $router->get("{$prefix}/{id}", [FeedbackApiController::class, 'show'], 'feedback.api.show');
    }

    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/feedback';

        $router->get($prefix, [AdminFeedbackController::class, 'index'], 'feedback.admin.index');
        $router->get("{$prefix}/{id}", [AdminFeedbackController::class, 'show'], 'feedback.admin.show');
        $router->put("{$prefix}/{id}/status", [AdminFeedbackController::class, 'updateStatus'], 'feedback.admin.update_status');
        $router->post("{$prefix}/{id}/respond", [AdminFeedbackController::class, 'respond'], 'feedback.admin.respond');
        $router->post("{$prefix}/{id}/github-issue", [AdminFeedbackController::class, 'createIssue'], 'feedback.admin.create_issue');
    }
}
