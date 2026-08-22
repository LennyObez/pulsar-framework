<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Controller\AssetController;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

use function is_dir;

/**
 * Serves framework and extension UI assets (CSS, JS, fonts) via PHP routes.
 *
 * In production, a web server (Nginx/Apache) should serve these files
 * directly for better performance. This wiring provides a zero-config
 * fallback that works with the PHP built-in server and any environment
 * where `pulsar asset:publish` has not been run.
 *
 * Registered paths (handled by {@see AssetController} so they compile into the
 * strict route cache):
 *   /ui/{path}          -> resources/ui/{path}   (framework design system)
 *   /cms/assets/{path}  -> extensions/cms/frontend/styles/{path} (CMS styles)
 */
#[Internal]
final readonly class AssetWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        // Only register asset routes if the assets exist (framework dev or symlink install)
        if (!is_dir(AssetController::uiAssetRoot())) {
            return;
        }

        $container->instance(AssetController::class, new AssetController());

        // /ui/{path} -> framework UI assets (CSS, fonts, JS)
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/ui/{path}',
            handler: [AssetController::class, 'ui'],
            name: 'pulsar.assets.ui',
            constraints: ['path' => '.+'],
        ));

        // /cms/assets/{path} -> CMS extension CSS
        if (is_dir(AssetController::cmsAssetRoot())) {
            $router->add(new Route(
                methods: [Method::GET, Method::HEAD],
                path: '/cms/assets/{path}',
                handler: [AssetController::class, 'cms'],
                name: 'pulsar.assets.cms',
                constraints: ['path' => '.+'],
            ));
        }
    }
}
