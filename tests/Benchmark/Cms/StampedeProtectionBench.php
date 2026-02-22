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

use function str_repeat;

/**
 * Stampede protection benchmark.
 *
 * Simulates concurrent requests for an expired cache entry.
 * Measures the cache-miss + store path under contention to verify
 * that the middleware handles stampede conditions gracefully.
 *
 * Since true concurrency requires proc_open/fibers, this benchmark
 * measures the sequential overhead of N cache-miss requests that all
 * miss the cache and need to render + store.
 *
 * Target: consistent per-request latency regardless of burst size.
 */
#[BeforeMethods('setUp')]
#[Revs(50)]
#[Iterations(5)]
#[Warmup(1)]
final class StampedeProtectionBench
{
    private CmsPageCacheMiddleware $middleware;
    private RequestHandlerInterface $handler;
    private InMemoryTaggedCache $cache;
    private int $renderCount;

    public function setUp(): void
    {
        $this->cache = new InMemoryTaggedCache();
        $config = new CmsCacheConfig(
            stampedeProtection: true,
            lockTimeoutSeconds: 5,
        );

        $this->middleware = new CmsPageCacheMiddleware($this->cache, $config);
        $this->renderCount = 0;

        $htmlBody = '<html><body>' . str_repeat('<p>Expensive rendered content.</p>', 20) . '</body></html>';

        $counter = &$this->renderCount;
        $this->handler = new class ($htmlBody, $counter) implements RequestHandlerInterface {
            public function __construct(
                private readonly string $body,
                private int &$renderCount,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->renderCount++;

                return new Response(
                    statusCode: 200,
                    headers: ['Content-Type' => 'text/html; charset=utf-8'],
                    body: $this->body,
                );
            }
        };
    }

    /**
     * Simulate a burst of 100 requests to the same expired page.
     * Without stampede protection, all 100 would render.
     * With protection, only 1 should render and 99 should serve from cache after the first stores.
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 50 milliseconds')]
    public function benchStampedeBurst(): void
    {
        $this->cache->clear();
        $this->renderCount = 0;

        for ($i = 0; $i < 100; $i++) {
            $request = new ServerRequest(method: 'GET', uri: '/stampede-page');
            $response = $this->middleware->process($request, $this->handler);
        }
    }

    /**
     * Measure per-request cost when cache is already warm (post-stampede).
     */
    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchPostStampedeHit(): void
    {
        // Warm up cache with one request
        $this->cache->clear();
        $warmupRequest = new ServerRequest(method: 'GET', uri: '/stampede-page');
        $this->middleware->process($warmupRequest, $this->handler);

        // Now measure a cache hit
        $request = new ServerRequest(method: 'GET', uri: '/stampede-page');
        $response = $this->middleware->process($request, $this->handler);
    }
}
