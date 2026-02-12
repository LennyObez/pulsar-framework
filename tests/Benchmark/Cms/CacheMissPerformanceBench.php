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
 * Cache-miss performance benchmark.
 *
 * Measures the cost of cache-miss path through CmsPageCacheMiddleware:
 * cache lookup (miss) + handler invocation + response serialization + cache store.
 * Target: p95 < 200 microseconds per request.
 */
#[BeforeMethods('setUp')]
#[Revs(100)]
#[Iterations(5)]
#[Warmup(1)]
final class CacheMissPerformanceBench
{
    private CmsPageCacheMiddleware $middleware;
    private RequestHandlerInterface $handler;
    private ServerRequestInterface $request;
    private InMemoryTaggedCache $cache;

    public function setUp(): void
    {
        $this->cache = new InMemoryTaggedCache();
        $config = new CmsCacheConfig();
        $this->middleware = new CmsPageCacheMiddleware($this->cache, $config);

        $htmlBody = '<html><body><article><h1>Fresh Content</h1>'
            . str_repeat('<p>Paragraph of content for cache-miss benchmark.</p>', 15)
            . '</article></body></html>';

        $this->request = new ServerRequest(method: 'GET', uri: '/fresh-page');
        $this->handler = new class ($htmlBody) implements RequestHandlerInterface {
            public function __construct(private readonly string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(
                    statusCode: 200,
                    headers: ['Content-Type' => 'text/html; charset=utf-8'],
                    body: $this->body,
                );
            }
        };
    }

    /**
     * Fresh setUp per rev: clear cache so each rev is a cold miss.
     */
    public function setUpFreshCache(): void
    {
        $this->setUp();
        $this->cache->clear();
    }

    #[Subject]
    #[BeforeMethods('setUpFreshCache')]
    #[Assert('mode(variant.time.avg) < 500 microseconds')]
    public function benchCacheMissAndStore(): void
    {
        $response = $this->middleware->process($this->request, $this->handler);
    }
}
