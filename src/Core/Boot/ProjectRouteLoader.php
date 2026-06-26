<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\Router;

use function dirname;

/**
 * Loads project-level route files (routes/web.php, routes/api.php).
 *
 * Scans relative to the config path's parent directory (the project root),
 * falling back to the current working directory. Each file receives the
 * Router and Container through PHP scope inheritance and can register routes
 * directly, or return a closure invoked with the Router. Extracted from
 * {@see \Pulsar\Core\Kernel}; boot-time only.
 */
#[Internal]
final class ProjectRouteLoader
{
    public static function load(?ConfigManager $configManager, Router $router, ContainerInterface $container): void
    {
        $configPath = $configManager?->configPath();

        if ($configPath !== null) {
            $projectRoot = dirname($configPath);
        } else {
            // Fallback: use current working directory when no config manager
            // is available (e.g., HTTP entry points without explicit config)
            $cwd = getcwd();

            if ($cwd === false) {
                return;
            }

            $projectRoot = $cwd;
        }

        $routesDir = $projectRoot . DIRECTORY_SEPARATOR . 'routes';

        $routeFiles = ['web.php', 'api.php'];

        foreach ($routeFiles as $file) {
            $routeFile = $routesDir . DIRECTORY_SEPARATOR . $file;

            if (is_file($routeFile)) {
                /**
                 * @psalm-suppress UnresolvableInclude
                 * @var mixed $result
                 */
                $result = (static function () use ($routeFile, $router, $container): mixed {
                    // The require'd file consumes $router and $container directly
                    // through PHP's scope inheritance; Psalm cannot trace through
                    // include so we bind them here and unset() after to mark them used.
                    /**
                     * @psalm-suppress UnresolvableInclude
                     * @var mixed $loaded
                     */
                    $loaded = require $routeFile;
                    unset($router, $container);

                    return $loaded;
                })();

                // Support route files that return a closure: invoke with router
                if ($result instanceof Closure) {
                    $result($router);
                }
            }
        }
    }
}
