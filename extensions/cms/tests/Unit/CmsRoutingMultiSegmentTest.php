<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

/**
 * Validates that CMS content routes with {path} constraints accept
 * multi-segment URL paths (e.g., docs/getting-started, blog/2026/03/my-post).
 */
#[CoversClass(Route::class)]
#[CoversClass(Router::class)]
final class CmsRoutingMultiSegmentTest extends TestCase
{
    #[Test]
    public function defaultParameterPatternRejectsSingleSegmentWithSlash(): void
    {
        // Without constraints, {path} matches [^/]+ (single segment only)
        $route = Route::get('/{path}', 'handler', 'no_constraint');
        $result = $route->matchesPath('/docs/getting-started');

        self::assertNull($result, 'Default [^/]+ constraint must reject multi-segment paths');
    }

    #[Test]
    public function dotPlusConstraintAcceptsSingleSegment(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/{path}',
            handler: 'handler',
            name: 'single_segment',
            constraints: ['path' => '.+'],
        );

        $result = $route->matchesPath('/about');

        self::assertNotNull($result);
        self::assertSame('about', $result['path']);
    }

    #[Test]
    public function dotPlusConstraintAcceptsMultiSegmentPath(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/{path}',
            handler: 'handler',
            name: 'multi_segment',
            constraints: ['path' => '.+'],
        );

        $result = $route->matchesPath('/docs/getting-started');

        self::assertNotNull($result, 'Route with .+ constraint must match multi-segment paths');
        self::assertSame('docs/getting-started', $result['path']);
    }

    #[Test]
    public function dotPlusConstraintAcceptsDeeplyNestedPath(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/{path}',
            handler: 'handler',
            name: 'deep_nested',
            constraints: ['path' => '.+'],
        );

        $result = $route->matchesPath('/blog/2026/03/27/my-post');

        self::assertNotNull($result);
        self::assertSame('blog/2026/03/27/my-post', $result['path']);
    }

    #[Test]
    public function localePrefixedRouteAcceptsMultiSegmentPath(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/fr/{path}',
            handler: 'handler',
            name: 'locale_multi',
            constraints: ['path' => '.+'],
        );

        $result = $route->matchesPath('/fr/docs/guide/installation');

        self::assertNotNull($result, 'Locale-prefixed route must match multi-segment content paths');
        self::assertSame('docs/guide/installation', $result['path']);
    }

    #[Test]
    public function adminAssetsRouteAcceptsSubdirectoryPaths(): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/admin/cms/assets/{path}',
            handler: 'handler',
            name: 'cms.admin.assets',
            constraints: ['path' => '.+'],
        );

        $result = $route->matchesPath('/admin/cms/assets/css/admin.css');

        self::assertNotNull($result, 'Admin assets route must match subdirectory paths');
        self::assertSame('css/admin.css', $result['path']);
    }

    #[Test]
    public function routerMatchesMultiSegmentContentRoute(): void
    {
        // Arrange: register routes the same way CmsExtension does after the fix
        $router = new Router();

        // Homepage root
        $router->get('/', 'homepage', 'cms.content.show.root');

        // Content catch-all with .+ constraint
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/{path}',
            handler: 'content_handler',
            name: 'cms.content.show',
            constraints: ['path' => '.+'],
        ));

        // Act
        $matched = $router->match(Method::GET, '/docs/getting-started');

        // Assert
        self::assertSame('content_handler', $matched->route->handler);
        self::assertSame('docs/getting-started', $matched->parameters['path']);
    }

    #[Test]
    public function routerMatchesSingleSegmentContentRoute(): void
    {
        $router = new Router();
        $router->get('/', 'homepage', 'root');
        $router->add(new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/{path}',
            handler: 'content_handler',
            name: 'cms.content.show',
            constraints: ['path' => '.+'],
        ));

        $matched = $router->match(Method::GET, '/about');

        self::assertSame('content_handler', $matched->route->handler);
        self::assertSame('about', $matched->parameters['path']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function multiSegmentPathProvider(): array
    {
        return [
            'two segments' => ['/docs/intro', 'docs/intro'],
            'three segments' => ['/api/v1/users', 'api/v1/users'],
            'four segments' => ['/blog/2026/03/title', 'blog/2026/03/title'],
            'with hyphens' => ['/getting-started/quick-start', 'getting-started/quick-start'],
            'deep nesting' => ['/a/b/c/d/e/f', 'a/b/c/d/e/f'],
        ];
    }

    #[Test]
    #[DataProvider('multiSegmentPathProvider')]
    public function routeMatchesVariousMultiSegmentPaths(string $requestPath, string $expectedParam): void
    {
        $route = new Route(
            methods: [Method::GET, Method::HEAD],
            path: '/{path}',
            handler: 'handler',
            constraints: ['path' => '.+'],
        );

        $result = $route->matchesPath($requestPath);

        self::assertNotNull($result);
        self::assertSame($expectedParam, $result['path']);
    }
}
