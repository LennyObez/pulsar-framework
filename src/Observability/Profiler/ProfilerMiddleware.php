<?php

declare(strict_types=1);

namespace Pulsar\Observability\Profiler;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Middleware\MiddlewareInterface;

use function implode;
use function round;

/**
 * Times each request with the {@see RequestProfiler} and exposes the totals as a
 * `Server-Timing` response header (visible in browser devtools).
 *
 * Begins the profiler at the start of the request and finishes at the end; DB
 * and cache instrumentation record into the same profiler in between. Wired
 * outermost so the measured total covers the whole middleware stack.
 */
#[Internal]
final readonly class ProfilerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private RequestProfiler $profiler,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->profiler->isEnabled()) {
            return $handler->handle($request);
        }

        $this->profiler->begin();

        $response = $handler->handle($request);

        $profile = $this->profiler->finish(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $response->getStatusCode(),
        );

        return $response->withHeader('Server-Timing', $this->serverTiming($profile));
    }

    private function serverTiming(RequestProfile $profile): string
    {
        $parts = ['total;dur=' . round($profile->totalMs, 2)];

        if ($profile->queryCount > 0) {
            $parts[] = 'db;dur=' . round($profile->queryTimeMs, 2) . ';desc="' . $profile->queryCount . ' queries"';
        }

        if ($profile->cacheHits > 0 || $profile->cacheMisses > 0) {
            $parts[] = 'cache;desc="' . $profile->cacheHits . ' hits, ' . $profile->cacheMisses . ' misses"';
        }

        return implode(', ', $parts);
    }
}
