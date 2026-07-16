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

use function hash;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

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

        // Pre-warm cache with a serialized response for the homepage path
        $pathHash = hash('xxh3', '');
        $cacheKey = sprintf('cms_page.%s.%s.%s', 'default', 'en', $pathHash);

        $cachedPayload = json_encode([
            'body' => '<html><body><h1>Home</h1><p>Cached homepage content.</p></body></html>',
            'status' => 200,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8'],
        ], JSON_THROW_ON_ERROR);

        $cache->seed($cacheKey, $cachedPayload, ['cms_pages', 'cms_settings']);

        $this->request = new ServerRequest(method: 'GET', uri: '/');
        $this->handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::html('<html><body><h1>Generated</h1></body></html>');
            }
        };
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchCachedHomepageHit(): void
    {
        $response = $this->middleware->process($this->request, $this->handler);
    }
}
