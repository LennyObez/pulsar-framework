<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Seo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Container;
use Pulsar\Extension\Cms\CmsExtension;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Http\Controller\SeoController;
use Pulsar\Extension\Cms\Http\Controller\SitemapController;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[CoversClass(CmsExtension::class)]
final class PublicSeoRouteRegistrationTest extends TestCase
{
    #[Test]
    public function sitemapXmlRouteIsRegistered(): void
    {
        $router = new Router();
        $container = new Container();
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        $container->instance(MiddlewareRegistry::class, new MiddlewareRegistry());

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        $routes = $router->routes();

        $routeNames = [];
        foreach ($routes as $route) {
            if ($route->name !== null) {
                $routeNames[] = $route->name;
            }
        }

        self::assertContains('cms.sitemap.index', $routeNames, '/sitemap.xml route must be registered');
        self::assertContains('cms.sitemap.type', $routeNames, '/sitemap/{contentType}.xml route must be registered');
    }

    #[Test]
    public function robotsTxtRouteIsRegistered(): void
    {
        $router = new Router();
        $container = new Container();
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        $container->instance(MiddlewareRegistry::class, new MiddlewareRegistry());

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        $routes = $router->routes();

        $routeNames = [];
        foreach ($routes as $route) {
            if ($route->name !== null) {
                $routeNames[] = $route->name;
            }
        }

        self::assertContains('cms.robots_txt', $routeNames, '/robots.txt route must be registered');
    }

    #[Test]
    public function sitemapRoutePointsToSitemapController(): void
    {
        $router = new Router();
        $container = new Container();
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        $container->instance(MiddlewareRegistry::class, new MiddlewareRegistry());

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        $routes = $router->routes();

        foreach ($routes as $route) {
            if ($route->name === 'cms.sitemap.index') {
                self::assertIsArray($route->handler);
                self::assertSame(SitemapController::class, $route->handler[0]);
                self::assertSame('index', $route->handler[1]);

                return;
            }
        }

        self::fail('cms.sitemap.index route not found');
    }

    #[Test]
    public function robotsRoutePointsToSeoController(): void
    {
        $router = new Router();
        $container = new Container();
        $container->instance(CmsConfig::class, CmsConfig::fromArray([]));
        $container->instance(MiddlewareRegistry::class, new MiddlewareRegistry());

        $extension = new CmsExtension();
        $extension->boot($container, $router);

        $routes = $router->routes();

        foreach ($routes as $route) {
            if ($route->name === 'cms.robots_txt') {
                self::assertIsArray($route->handler);
                self::assertSame(SeoController::class, $route->handler[0]);
                self::assertSame('robotsTxt', $route->handler[1]);

                return;
            }
        }

        self::fail('cms.robots_txt route not found');
    }
}
