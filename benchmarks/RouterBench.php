<?php

declare(strict_types=1);

namespace Pulsar\Benchmarks;

use Pulsar\Http\Method;
use Pulsar\Routing\Router;

/**
 * Benchmarks for the HTTP router.
 *
 * @BeforeMethods("setUp")
 * @Revs(1000)
 * @Iterations(5)
 */
final class RouterBench
{
    private Router $routerSmall;
    private Router $routerMedium;
    private Router $routerLarge;
    private Router $routerWithParams;

    public function setUp(): void
    {
        // Small router: 10 routes
        $this->routerSmall = $this->createRouter(10);

        // Medium router: 50 routes
        $this->routerMedium = $this->createRouter(50);

        // Large router: 200 routes
        $this->routerLarge = $this->createRouter(200);

        // Router with parameterized routes
        $this->routerWithParams = new Router();
        for ($i = 0; $i < 50; $i++) {
            $this->routerWithParams->get("/users/{id}/posts/{postId}/comments/{commentId}/path$i", fn() => null);
        }
    }

    private function createRouter(int $count): Router
    {
        $router = new Router();

        for ($i = 0; $i < $count; $i++) {
            $router->get("/route$i", fn() => null);
        }

        return $router;
    }

    /**
     * Benchmark: Match first route in small router.
     *
     * Best case - route found immediately.
     *
     * @Subject
     */
    public function benchSmallRouterFirstRoute(): void
    {
        $this->routerSmall->match(Method::GET, '/route0');
    }

    /**
     * Benchmark: Match last route in small router.
     *
     * Worst case for small router.
     *
     * @Subject
     */
    public function benchSmallRouterLastRoute(): void
    {
        $this->routerSmall->match(Method::GET, '/route9');
    }

    /**
     * Benchmark: Match middle route in medium router.
     *
     * Average case for medium router.
     *
     * @Subject
     */
    public function benchMediumRouterMiddleRoute(): void
    {
        $this->routerMedium->match(Method::GET, '/route25');
    }

    /**
     * Benchmark: Match last route in large router.
     *
     * Worst case for large router.
     *
     * @Subject
     */
    public function benchLargeRouterLastRoute(): void
    {
        $this->routerLarge->match(Method::GET, '/route199');
    }

    /**
     * Benchmark: Match route with parameters.
     *
     * Tests regex-based parameter extraction.
     *
     * @Subject
     */
    public function benchParameterizedRouteMatch(): void
    {
        $this->routerWithParams->match(Method::GET, '/users/123/posts/456/comments/789/path25');
    }

    /**
     * Benchmark: Route registration.
     *
     * @Subject
     * @BeforeMethods("setUpFresh")
     */
    public function benchRouteRegistration(): void
    {
        $this->routerSmall->get('/new-route', fn() => null);
    }

    public function setUpFresh(): void
    {
        $this->routerSmall = new Router();
    }

    /**
     * Benchmark: URL generation for named route.
     *
     * @Subject
     * @BeforeMethods("setUpNamedRoutes")
     */
    public function benchUrlGeneration(): void
    {
        $this->routerSmall->url('route.5', ['id' => '123']);
    }

    public function setUpNamedRoutes(): void
    {
        $this->routerSmall = new Router();
        for ($i = 0; $i < 10; $i++) {
            $this->routerSmall->get("/route$i/{id}", fn() => null, "route.$i");
        }
    }
}
