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
use Pulsar\Routing\RouterInterface;

/**
 * Release management extension for version history and beta signups.
 *
 * Provides a public API for querying the latest release version per platform,
 * browsing paginated release history, and registering for the beta program.
 * Includes an admin panel for CRUD operations on releases and viewing beta
 * signup lists.
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

    private function registerAdminRoutes(RouterInterface $router): void
    {
        $prefix = '/admin/releases';

        $router->get($prefix, [AdminReleaseController::class, 'index'], 'releases.admin.index');
        $router->get("$prefix/create", [AdminReleaseController::class, 'create'], 'releases.admin.create');
        $router->post($prefix, [AdminReleaseController::class, 'store'], 'releases.admin.store');
        $router->get("$prefix/{id}", [AdminReleaseController::class, 'edit'], 'releases.admin.edit');
        $router->put("$prefix/{id}", [AdminReleaseController::class, 'update'], 'releases.admin.update');
        $router->get("$prefix/beta-signups", [AdminReleaseController::class, 'betaSignups'], 'releases.admin.beta_signups');
    }
}
