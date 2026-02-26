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
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;
use Pulsar\Extension\Cms\Http\Middleware\CommentAntiAbuseMiddleware;
use Pulsar\Extension\Cms\Http\Middleware\CommentRateLimitMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Tests\Benchmark\Cms\Support\InMemoryTaggedCache;

/**
 * Comment spam burst benchmark.
 *
 * Measures the rate-limiting and anti-abuse middleware performance under
 * burst conditions: many requests from the same IP with varying comment bodies.
 *
 * Validates that the middleware stack correctly rejects spam without degrading
 * performance for legitimate requests.
 *
 * Target: correct rejection with stable latency across burst size.
 */
#[BeforeMethods('setUp')]
#[Revs(1000)]
#[Iterations(5)]
#[Warmup(1)]
final class CommentSpamBurstBench
{
    private CommentAntiAbuseMiddleware $antiAbuseMiddleware;
    private CommentRateLimitMiddleware $rateLimitMiddleware;
    private RequestHandlerInterface $handler;
    private InMemoryTaggedCache $cache;

    public function setUp(): void
    {
        $this->cache = new InMemoryTaggedCache();
        $heuristics = new AntiAbuseHeuristics($this->cache);

        $this->antiAbuseMiddleware = new CommentAntiAbuseMiddleware($heuristics);
        $this->rateLimitMiddleware = new CommentRateLimitMiddleware(
            cache: $this->cache,
            rateLimitPerMinute: 5,
            rateLimitPerHour: 30,
        );

        $this->handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::json(['status' => 'comment_accepted']);
            }
        };
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchAntiAbuseCleanComment(): void
    {
        $request = $this->createCommentRequest('A thoughtful and unique benchmark comment.');
        $response = $this->antiAbuseMiddleware->process($request, $this->handler);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchAntiAbuseLinkSpam(): void
    {
        $body = 'Check out https://spam1.com and https://spam2.com and https://spam3.com and https://spam4.com';
        $request = $this->createCommentRequest($body);
        $response = $this->antiAbuseMiddleware->process($request, $this->handler);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchAntiAbuseRepetition(): void
    {
        $body = 'aaaaaaaaaaaaaaaaaaaaaa this is spam';
        $request = $this->createCommentRequest($body);
        $response = $this->antiAbuseMiddleware->process($request, $this->handler);
    }

    #[Subject]
    #[Assert('mode(variant.time.avg) < 100 microseconds')]
    public function benchRateLimitCheck(): void
    {
        $request = $this->createCommentRequest('Rate limit test comment');
        $response = $this->rateLimitMiddleware->process($request, $this->handler);
    }

    private function createCommentRequest(string $body): ServerRequest
    {
        $request = new ServerRequest(
            method: 'POST',
            uri: '/comments',
            serverParams: ['REMOTE_ADDR' => '192.168.1.100'],
        );

        return $request
            ->withParsedBody(['body' => $body, 'content_id' => 'bench-content-1']);
    }
}
