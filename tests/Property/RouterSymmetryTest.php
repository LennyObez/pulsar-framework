<?php

declare(strict_types=1);

namespace Pulsar\Tests\Property;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(MatchedRoute::class)]
#[Group('property')]
final class RouterSymmetryTest extends TestCase
{
    /**
     * Wraps a plain handler string as class-string for PHPStan.
     *
     * @return class-string
     */
    private static function handler(string $name): string
    {
        /** @var class-string */
        return $name;
    }

    #[Test]
    public function urlGenerationAndMatchingAreSymmetric(): void
    {
        $router = new Router();
        $router->get('/users/{id}', self::handler('UserController'), 'user.show');
        $router->get('/posts/{postId}/comments/{commentId}', self::handler('CommentController'), 'post.comment.show');
        $router->get('/api/v{version}/resources/{id}', self::handler('ApiController'), 'api.resource');

        $testCases = [
            ['user.show', ['id' => '42'], Method::GET],
            ['user.show', ['id' => '1'], Method::GET],
            ['user.show', ['id' => 'abc'], Method::GET],
            ['post.comment.show', ['postId' => '10', 'commentId' => '5'], Method::GET],
            ['api.resource', ['version' => '2', 'id' => '100'], Method::GET],
        ];

        foreach ($testCases as [$routeName, $params, $method]) {
            // Generate URL from route + params
            $url = $router->url($routeName, $params);

            // Match the generated URL back to a route
            $matched = $router->match($method, $url);

            // The matched route should be the same named route
            self::assertSame(
                $routeName,
                $matched->getName(),
                "URL '{$url}' generated for '{$routeName}' matched a different route: '{$matched->getName()}'",
            );

            // The extracted parameters should match the original params
            foreach ($params as $key => $value) {
                self::assertSame(
                    $value,
                    $matched->parameter($key),
                    "Parameter '{$key}' mismatch for route '{$routeName}'",
                );
            }
        }
    }

    #[Test]
    public function staticRoutesMatchExactly(): void
    {
        $router = new Router();

        $staticPaths = [
            '/home' => 'home',
            '/about' => 'about',
            '/api/health' => 'api.health',
            '/api/v1/status' => 'api.v1.status',
            '/admin/dashboard' => 'admin.dashboard',
        ];

        foreach ($staticPaths as $path => $name) {
            $router->get($path, self::handler('Controller'), $name);
        }

        foreach ($staticPaths as $path => $name) {
            $matched = $router->match(Method::GET, $path);
            self::assertSame($name, $matched->getName());
            self::assertSame([], $matched->parameters);

            // URL generation should return the exact path
            $url = $router->url($name);
            self::assertSame($path, $url);
        }
    }

    #[Test]
    public function optionalParameterRoutesAreSymmetric(): void
    {
        $router = new Router();
        // Register both the with-page and without-page variants to test optional params
        $router->get('/articles/{slug}', self::handler('ArticleController'), 'article.list');
        $router->get('/articles/{slug}/{page}', self::handler('ArticleController'), 'article.show');

        // With page parameter
        $urlWithPage = $router->url('article.show', ['slug' => 'my-article', 'page' => '2']);
        self::assertSame('/articles/my-article/2', $urlWithPage);

        $matched = $router->match(Method::GET, $urlWithPage);
        self::assertSame('article.show', $matched->getName());
        self::assertSame('my-article', $matched->parameter('slug'));
        self::assertSame('2', $matched->parameter('page'));

        // Without page parameter
        $urlWithoutPage = $router->url('article.list', ['slug' => 'my-article']);
        self::assertSame('/articles/my-article', $urlWithoutPage);

        $matched2 = $router->match(Method::GET, $urlWithoutPage);
        self::assertSame('article.list', $matched2->getName());
        self::assertSame('my-article', $matched2->parameter('slug'));
    }

    #[Test]
    public function routeGroupPrefixingIsTransparent(): void
    {
        $router = new Router();
        $router->group('/api/v1', function (Router $r): void {
            $r->get('/users', self::handler('UserController'), 'api.users.index');
            $r->get('/users/{id}', self::handler('UserController'), 'api.users.show');
            $r->post('/users', self::handler('UserController'), 'api.users.create');
        });

        // Match prefixed routes
        $index = $router->match(Method::GET, '/api/v1/users');
        self::assertSame('api.users.index', $index->getName());

        $show = $router->match(Method::GET, '/api/v1/users/42');
        self::assertSame('api.users.show', $show->getName());
        self::assertSame('42', $show->parameter('id'));

        $create = $router->match(Method::POST, '/api/v1/users');
        self::assertSame('api.users.create', $create->getName());

        // URL generation should include the prefix
        $generatedUrl = $router->url('api.users.show', ['id' => '42']);
        self::assertSame('/api/v1/users/42', $generatedUrl);
    }

    #[Test]
    public function constrainedRouteMatchingIsConsistentWithGeneration(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/orders/{id}',
            handler: self::handler('OrderController'),
            name: 'order.show',
            constraints: ['id' => '\d+'],
        ));

        // Generate URL with valid parameter
        $url = $router->url('order.show', ['id' => '12345']);
        self::assertSame('/orders/12345', $url);

        // Match the generated URL
        $matched = $router->match(Method::GET, $url);
        self::assertSame('order.show', $matched->getName());
        self::assertSame('12345', $matched->parameter('id'));
    }

    #[Test]
    public function allHttpMethodsRouteCorrectly(): void
    {
        $router = new Router();
        $router->get('/resource', self::handler('Controller@index'), 'resource.index');
        $router->post('/resource', self::handler('Controller@create'), 'resource.create');
        $router->put('/resource/{id}', self::handler('Controller@update'), 'resource.update');
        $router->patch('/resource/{id}', self::handler('Controller@patch'), 'resource.patch');
        $router->delete('/resource/{id}', self::handler('Controller@delete'), 'resource.delete');

        // Each method matches the correct route
        self::assertSame('resource.index', $router->match(Method::GET, '/resource')->getName());
        self::assertSame('resource.create', $router->match(Method::POST, '/resource')->getName());
        self::assertSame('resource.update', $router->match(Method::PUT, '/resource/1')->getName());
        self::assertSame('resource.patch', $router->match(Method::PATCH, '/resource/1')->getName());
        self::assertSame('resource.delete', $router->match(Method::DELETE, '/resource/1')->getName());
    }

    #[Test]
    public function namedRouteRetrievalIsConsistent(): void
    {
        $router = new Router();

        $routeNames = [];
        for ($i = 0; $i < 50; $i++) {
            $name = "route.{$i}";
            $routeNames[] = $name;
            $router->get("/path-{$i}", self::handler("Controller{$i}"), $name);
        }

        foreach ($routeNames as $name) {
            $route = $router->getByName($name);
            self::assertNotNull($route, "Named route '{$name}' should be retrievable");
            self::assertSame($name, $route->name);
        }

        self::assertNull($router->getByName('nonexistent.route'));
    }

    #[Test]
    public function routeCountIsAccurate(): void
    {
        $router = new Router();
        self::assertSame(0, $router->count());

        for ($i = 1; $i <= 20; $i++) {
            $router->get("/route-{$i}", self::handler("Controller{$i}"));
            self::assertSame($i, $router->count());
        }
    }

    #[Test]
    public function hostBasedRoutingIsSymmetric(): void
    {
        $router = new Router();
        $router->add(new Route(
            methods: [Method::GET],
            path: '/dashboard',
            handler: self::handler('DashboardController'),
            name: 'tenant.dashboard',
            host: '{tenant}.example.com',
        ));

        $matched = $router->match(Method::GET, '/dashboard', 'acme.example.com');
        self::assertSame('tenant.dashboard', $matched->getName());
        self::assertSame('acme', $matched->parameter('tenant'));
    }
}
