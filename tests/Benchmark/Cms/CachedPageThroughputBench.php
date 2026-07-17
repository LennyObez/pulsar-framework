<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms;

use PhpBench\Attributes\Assert;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Cms\Config\CmsCacheConfig;
use Pulsar\Extension\Cms\Http\Middleware\CmsPageCacheMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\InMemoryTaggedCache;

/**
 * Cached page throughput benchmark.
 *
 * Simulates cache-hit requests through CmsPageCacheMiddleware.
 * Target: p99 < 100ms (dominated by serialization overhead on cache hit).
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(2)]
final class CachedPageThroughputBench
{
    private CmsPageCacheMiddleware $middleware;
    private RequestHandlerInterface $handler;
    private ServerRequestInterface $request;

    public function setUp(): void
    {
        $cache = new InMemoryTaggedCache();
        $config = new CmsCacheConfig();
        $this->middleware = new CmsPageCacheMiddleware($cache, $config);

        $this->request = new ServerRequest(method: 'GET', uri: '/');
        $this->handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::html('<html><body><h1>Home</h1><p>Cached homepage content.</p></body></html>');
            }
        };

        // Warm through the middleware itself so the entry uses the real key
        // format and envelope; hand-seeding would silently measure the miss
        // path whenever either evolves.
        $this->middleware->process($this->request, $this->handler);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchCachedHomepageHit(): void
    {
        $response = $this->middleware->process($this->request, $this->handler);
    }
}
