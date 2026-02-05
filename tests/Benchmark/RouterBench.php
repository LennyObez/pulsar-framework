<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Pulsar\Http\Method;
use Pulsar\Routing\Router;

#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class RouterBench
{
    private Router $routerSmall;
    private Router $routerMedium;
    private Router $routerLarge;
    private Router $routerWithParams;

    public function setUp(): void
    {
        $this->routerSmall = $this->createRouter(10);
        $this->routerMedium = $this->createRouter(50);
        $this->routerLarge = $this->createRouter(200);

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
     * Best case: route found immediately in a small router.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchSmallRouterFirstRoute(): void
    {
        $this->routerSmall->match(Method::GET, '/route0');
    }

    /**
     * Worst case for small router.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 5 microseconds')]
    public function benchSmallRouterLastRoute(): void
    {
        $this->routerSmall->match(Method::GET, '/route9');
    }

    /**
     * Average case for medium router.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
    public function benchMediumRouterMiddleRoute(): void
    {
        $this->routerMedium->match(Method::GET, '/route25');
    }

    /**
     * Worst case for large router.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchLargeRouterLastRoute(): void
    {
        $this->routerLarge->match(Method::GET, '/route199');
    }

    /**
     * Regex-based parameter extraction with multiple segments.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 200 microseconds')]
    public function benchParameterizedRouteMatch(): void
    {
        $this->routerWithParams->match(Method::GET, '/users/123/posts/456/comments/789/path25');
    }

    /**
     * Route registration throughput.
     */
    #[Subject]
    #[BeforeMethods('setUpFresh')]
    #[Assert('mode(variant.time.avg) < 1 millisecond')]
    public function benchRouteRegistration(): void
    {
        $this->routerSmall->get('/new-route', fn() => null);
    }

    public function setUpFresh(): void
    {
        $this->routerSmall = new Router();
    }

    /**
     * URL generation for named route with parameters.
     */
    #[Subject]
    #[BeforeMethods('setUpNamedRoutes')]
    #[Assert('mode(variant.time.avg) < 50 microseconds')]
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
