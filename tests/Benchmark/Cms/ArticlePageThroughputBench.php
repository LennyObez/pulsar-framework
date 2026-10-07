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
 * Article page throughput benchmark.
 *
 * Simulates cache-hit requests for article pages (larger payload than homepage).
 * Target: p99 < 150ms.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(2)]
final class ArticlePageThroughputBench
{
    private CmsPageCacheMiddleware $middleware;
    private RequestHandlerInterface $handler;
    private ServerRequestInterface $request;

    public function setUp(): void
    {
        $cache = new InMemoryTaggedCache();
        $config = new CmsCacheConfig();
        $this->middleware = new CmsPageCacheMiddleware($cache, $config);

        $articleBody = '<html><body><article><h1>Benchmark Article</h1>'
            . str_repeat('<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>', 30)
            . '</article></body></html>';

        $this->request = new ServerRequest(method: 'GET', uri: '/blog/benchmark-article');

        // Warm through the middleware itself (real key format, v2 envelope,
        // tags via the internal header) so the benchmark measures the actual
        // hit path instead of a hand-seeded layout that drifts from reality.
        $warmHandler = new class ($articleBody) implements RequestHandlerInterface {
            public function __construct(private readonly string $body) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::html($this->body)
                    ->withHeader('Content-Language', 'en')
                    ->withHeader(
                        CmsPageCacheMiddleware::TAGS_HEADER,
                        'cms_content.art-1,cms_type.article',
                    );
            }
        };
        $this->middleware->process($this->request, $warmHandler);

        $this->handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::html('<html><body>Should not be reached on cache hit</body></html>');
            }
        };
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 150 microseconds')]
    public function benchCachedArticleHit(): void
    {
        $response = $this->middleware->process($this->request, $this->handler);
    }
}
