<?php

declare(strict_types=1);

namespace {{namespace}}\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;

/**
 * Rate limiting middleware stub.
 *
 * Tracks request counts per client and enforces rate limits.
 * Replace the in-memory storage with a persistent store (Redis, etc.)
 * for production use.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private const int DEFAULT_LIMIT = 60;
    private const int WINDOW_SECONDS = 60;

    /** @var array<string, array{count: int, reset: int}> */
    private array $counters = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $clientId = $this->resolveClientId($request);
        $now = time();

        // Initialize or reset counter if window has expired
        if (!isset($this->counters[$clientId]) || $this->counters[$clientId]['reset'] <= $now) {
            $this->counters[$clientId] = [
                'count' => 0,
                'reset' => $now + self::WINDOW_SECONDS,
            ];
        }

        $this->counters[$clientId]['count']++;
        $remaining = max(0, self::DEFAULT_LIMIT - $this->counters[$clientId]['count']);
        $resetAt = $this->counters[$clientId]['reset'];

        // Check if limit exceeded
        if ($this->counters[$clientId]['count'] > self::DEFAULT_LIMIT) {
            return Response::json([
                'error' => 'rate_limit_exceeded',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => $resetAt - $now,
            ], 429);
        }

        $response = $handler->handle($request);

        // Rate limit headers would be added here in a full implementation
        return $response;
    }

    /**
     * Resolve a client identifier from the request.
     */
    private function resolveClientId(ServerRequestInterface $request): string
    {
        // Prefer API key, fall back to IP address
        $apiKey = $request->getHeaderLine('X-API-Key');

        if ($apiKey !== '') {
            return 'key:' . hash('sha256', $apiKey);
        }

        $serverParams = $request->getServerParams();

        return 'ip:' . ($serverParams['REMOTE_ADDR'] ?? 'unknown');
    }
}
