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
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

/**
 * Feedback extension for user feedback collection with admin triage.
 *
 * Provides a public API for authenticated users to submit and view their
 * feedback, and an admin panel for triaging, responding, and linking
 * feedback to GitHub issues.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
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
        $router->get("$prefix/{id}", [FeedbackApiController::class, 'show'], 'feedback.api.show');
    }

    /**
     * Admin routes, every one of them behind the `auth` alias with an explicit
     * permission.
     *
     * They were registered with the bare router sugar, which attaches no middleware:
     * an anonymous request reached them the moment the extension was enabled. The
     * worst of them is create_issue — it sends the server's stored GitHub token to
     * whatever repository the request names, so an unauthenticated POST leaks a
     * credential rather than merely reading data.
     *
     * AuthorizationMiddleware default-denies a route declaring no permissions, so a
     * route added here without an attribute fails closed rather than open.
     */
    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/feedback';

        $this->guarded($router, Method::GET, $prefix, [AdminFeedbackController::class, 'index'], 'feedback.admin.index', ['feedback.read']);
        $this->guarded($router, Method::GET, "$prefix/{id}", [AdminFeedbackController::class, 'show'], 'feedback.admin.show', ['feedback.read']);
        $this->guarded($router, Method::PUT, "$prefix/{id}/status", [AdminFeedbackController::class, 'updateStatus'], 'feedback.admin.update_status', ['feedback.triage']);
        $this->guarded($router, Method::POST, "$prefix/{id}/respond", [AdminFeedbackController::class, 'respond'], 'feedback.admin.respond', ['feedback.triage']);
        $this->guarded($router, Method::POST, "$prefix/{id}/github-issue", [AdminFeedbackController::class, 'createIssue'], 'feedback.admin.create_issue', ['feedback.github']);
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<string>                      $permissions
     */
    private function guarded(RouterInterface $router, Method $method, string $path, array $handler, string $name, array $permissions): void
    {
        $router->add(new Route(
            methods: [$method],
            path: $path,
            handler: $handler,
            name: $name,
            attributes: ['permissions' => $permissions],
            middleware: ['auth'],
        ));
    }
}
