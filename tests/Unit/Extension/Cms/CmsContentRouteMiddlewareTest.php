<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Cms\CmsExtension;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsLocaleMiddleware;
use Pulsar\Routing\Router;

use function array_filter;
use function str_contains;

/**
 * Verifies that the CMS content catch-all routes have
 * CmsLocaleMiddleware registered as route-level middleware.
 */
#[CoversClass(CmsExtension::class)]
final class CmsContentRouteMiddlewareTest extends TestCase
{
    #[Test]
    public function contentShowRoutesHaveLocaleMiddleware(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();

        $config = CmsConfig::fromArray([
            'site_name' => 'Test',
            'supported_locales' => ['en', 'fr'],
            'default_locale' => 'en',
        ]);
        $container->instance(CmsConfig::class, $config);

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        // Act: find all content.show routes
        $contentRoutes = array_filter(
            $router->routes(),
            static fn($route) => $route->name !== null
                && str_contains($route->name, 'cms.content.show'),
        );

        // Assert: each content route must include CmsLocaleMiddleware
        self::assertNotEmpty($contentRoutes, 'Expected at least one content show route');

        foreach ($contentRoutes as $route) {
            self::assertContains(
                CmsLocaleMiddleware::class,
                $route->middleware,
                "Route '{$route->name}' (path: {$route->path}) must have CmsLocaleMiddleware",
            );
        }
    }

    #[Test]
    public function rootContentRouteHasLocaleMiddleware(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();

        $config = CmsConfig::fromArray([
            'site_name' => 'Test',
            'supported_locales' => ['en'],
            'default_locale' => 'en',
        ]);
        $container->instance(CmsConfig::class, $config);

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        // Act: find the root route
        $rootRoutes = array_filter(
            $router->routes(),
            static fn($route) => $route->name === 'cms.content.show.root',
        );

        // Assert
        self::assertNotEmpty($rootRoutes, 'Expected cms.content.show.root route');
        $rootRoute = reset($rootRoutes);
        self::assertNotFalse($rootRoute);
        self::assertContains(
            CmsLocaleMiddleware::class,
            $rootRoute->middleware,
        );
    }

    #[Test]
    public function localeSpecificContentRoutesHaveMiddleware(): void
    {
        // Arrange
        $container = new Container();
        $router = new Router();

        $config = CmsConfig::fromArray([
            'site_name' => 'Test',
            'supported_locales' => ['en', 'fr', 'de'],
            'default_locale' => 'en',
        ]);
        $container->instance(CmsConfig::class, $config);

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        // Act: find locale-specific routes (fr.root, fr, de.root, de)
        $localeRoutes = array_filter(
            $router->routes(),
            static fn($route) => $route->name !== null
                && (str_contains($route->name, 'cms.content.show.fr')
                    || str_contains($route->name, 'cms.content.show.de')),
        );

        // Assert
        self::assertNotEmpty($localeRoutes, 'Expected locale-specific content routes');

        foreach ($localeRoutes as $route) {
            self::assertContains(
                CmsLocaleMiddleware::class,
                $route->middleware,
                "Locale route '{$route->name}' must have CmsLocaleMiddleware",
            );
        }
    }
}
