<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\Releases\Http\Controller\Admin\ReleaseController as AdminReleaseController;
use Pulsar\Extension\Releases\Http\Controller\Api\BetaSignupController;
use Pulsar\Extension\Releases\Http\Controller\Api\ReleaseApiController;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouterInterface;

/**
 * Release management extension for version history and beta signups.
 *
 * Provides a public API for querying the latest release version per platform,
 * browsing paginated release history, and registering for the beta program.
 * Includes an admin panel for CRUD operations on releases and viewing beta
 * signup lists.
 *
 * @psalm-api Loaded by the framework's ExtensionLoader at boot time
 *            via the pulsar.json manifest, never instantiated by name.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReleasesExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/releases';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider (ReleasesServiceProvider) handles all bindings.
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
            ReleasesServiceProvider::class,
        ];
    }

    private function registerApiRoutes(RouterInterface $router): void
    {
        $router->get('/api/v1/version', [ReleaseApiController::class, 'latestVersion'], 'releases.api.latest_version');
        $router->get('/api/v1/releases', [ReleaseApiController::class, 'listReleases'], 'releases.api.list');
        $router->post('/api/v1/beta/signup', [BetaSignupController::class, 'signup'], 'releases.api.beta_signup');
    }

    /**
     * Admin routes, every one of them behind the `auth` alias with an explicit
     * permission.
     *
     * They were registered with the bare router sugar, which attaches no middleware
     * at all: an anonymous GET reached them the moment the extension was enabled.
     * That is not a theoretical exposure — /admin/releases/beta-signups returns every
     * beta subscriber's email address, and POST /admin/releases sets the download URL
     * that the public /api/v1/version hands to clients.
     *
     * AuthorizationMiddleware default-denies a route that declares no permissions, so
     * a route added here without an attribute fails closed rather than open.
     */
    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/releases';

        $this->guarded($router, Method::GET, $prefix, [AdminReleaseController::class, 'index'], 'releases.admin.index', ['releases.read']);
        $this->guarded($router, Method::GET, "$prefix/create", [AdminReleaseController::class, 'create'], 'releases.admin.create', ['releases.manage']);
        $this->guarded($router, Method::POST, $prefix, [AdminReleaseController::class, 'store'], 'releases.admin.store', ['releases.manage']);
        $this->guarded($router, Method::GET, "$prefix/{id}", [AdminReleaseController::class, 'edit'], 'releases.admin.edit', ['releases.read']);
        $this->guarded($router, Method::PUT, "$prefix/{id}", [AdminReleaseController::class, 'update'], 'releases.admin.update', ['releases.manage']);

        // Subscriber email addresses: a stricter permission than the rest of the
        // admin surface, because reading them is a personal-data disclosure.
        $this->guarded($router, Method::GET, "$prefix/beta-signups", [AdminReleaseController::class, 'betaSignups'], 'releases.admin.beta_signups', ['releases.beta_signups.read']);
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
