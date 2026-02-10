<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Interceptor;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Extension\Grpc\Config\RateLimitConfig;
use Pulsar\Extension\Grpc\Error\GrpcStatus;

use function count;
use function hrtime;

/**
 * Interceptor that rate-limits gRPC calls using a token bucket algorithm.
 *
 * Maintains per-method (and optionally per-client) in-memory token buckets.
 * When the bucket is empty, the call is rejected with RESOURCE_EXHAUSTED.
 *
 * Uses atomic decrement-first to avoid TOCTOU race conditions, and caps
 * the total number of buckets with LRU eviction to prevent unbounded growth.
 */
#[Internal(reason: 'Pipeline implementation detail — use InterceptorPipeline')]
final class RateLimitInterceptor implements InterceptorInterface
{
    private const int MAX_BUCKETS = 10_000;

    /** @var array<string, float> Current token count per key */
    private array $tokens = [];

    /** @var array<string, float> Last refill timestamp (seconds) per key */
    private array $lastRefill = [];

    /** @var (Closure(): float) */
    private readonly Closure $clock;

    /**
     * @param (Closure(): float)|null $clock Injectable clock for testability (returns seconds as float)
     */
    public function __construct(
        private readonly RateLimitConfig $config,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
    }

    public function handle(CallContext $context, Closure $next): InterceptorResult
    {
        $key = $this->buildKey($context);

        $this->refill($key);

        // Atomic decrement-first: decrement then check, avoiding TOCTOU race
        $this->tokens[$key] -= 1.0;

        if ($this->tokens[$key] < 0.0) {
            // Restore the token — this request was not served
            $this->tokens[$key] += 1.0;

            return InterceptorResult::error(
                GrpcStatus::ResourceExhausted,
                'Rate limit exceeded',
            );
        }

        return $next($context);
    }

    /**
     * Build a composite key from method + peer identity (when available).
     */
    private function buildKey(CallContext $context): string
    {
        $key = $context->method->fullName;

        if ($context->peerIdentity !== null) {
            $key .= "\0" . $context->peerIdentity;
        }

        return $key;
    }

    private function refill(string $key): void
    {
        $now = ($this->clock)();

        if (!isset($this->lastRefill[$key])) {
            $this->evictIfNeeded();
            $this->tokens[$key] = (float) $this->config->burstSize;
            $this->lastRefill[$key] = $now;

            return;
        }

        $elapsed = $now - $this->lastRefill[$key];
        $newTokens = $elapsed * $this->config->maxRequestsPerSecond;

        if ($newTokens > 0.0) {
            $this->tokens[$key] = min(
                (float) $this->config->burstSize,
                $this->tokens[$key] + $newTokens,
            );
            $this->lastRefill[$key] = $now;
        }
    }

    /**
     * Evict the oldest bucket (by lastRefill) when the cap is exceeded.
     */
    private function evictIfNeeded(): void
    {
        if (count($this->lastRefill) < self::MAX_BUCKETS) {
            return;
        }

        // Find the key with the oldest lastRefill timestamp
        $oldestKey = null;
        $oldestTime = PHP_FLOAT_MAX;

        foreach ($this->lastRefill as $k => $time) {
            if ($time < $oldestTime) {
                $oldestTime = $time;
                $oldestKey = $k;
            }
        }

        if ($oldestKey !== null) {
            unset($this->tokens[$oldestKey], $this->lastRefill[$oldestKey]);
        }
    }
}
