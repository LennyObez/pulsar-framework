<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

use function max;

/**
 * Adaptive rate limiter that tightens limits during detected attack patterns.
 *
 * Wraps a base RateLimiterInterface and adjusts effective limits based on:
 * - Per-client reputation scoring (good clients get higher limits)
 * - Global threat level (tightens all limits during active attacks)
 * - Per-endpoint configuration (stricter for auth endpoints)
 *
 * The adaptive behavior is layered on top of the base limiter, so the
 * underlying storage and window mechanics remain unchanged.
 * @api
 */
#[Api(since: '1.0.0')]
final class AdaptiveRateLimiter implements RateLimiterInterface
{
    /** @var array<string, ClientReputation> key => reputation */
    private array $reputations = [];

    private ThreatLevel $threatLevel = ThreatLevel::Normal;

    /** @var array<string, EndpointRateLimitPolicy> endpoint => policy */
    private array $endpointPolicies = [];

    public function __construct(
        private readonly RateLimiterInterface $baseLimiter,
        private readonly int $baseLimit,
    ) {}

    public function hit(string $key): RateLimitResult
    {
        $effectiveLimit = $this->computeEffectiveLimit($key);

        $result = $this->baseLimiter->hit($key);

        $attempts = $this->baseLimiter->attempts($key);

        if ($attempts > $effectiveLimit) {
            $this->degradeReputation($key);

            return new RateLimitResult(
                allowed: false,
                limit: $effectiveLimit,
                remaining: 0,
                retryAfter: max(1, $result->retryAfter),
            );
        }

        if ($result->allowed) {
            $this->improveReputation($key);
        }

        return new RateLimitResult(
            allowed: $result->allowed,
            limit: $effectiveLimit,
            remaining: max(0, $effectiveLimit - $attempts),
            retryAfter: $result->retryAfter,
        );
    }

    public function attempts(string $key): int
    {
        return $this->baseLimiter->attempts($key);
    }

    public function reset(string $key): void
    {
        $this->baseLimiter->reset($key);
        unset($this->reputations[$key]);
    }

    /**
     * Register a rate limit policy for a specific endpoint pattern.
     */
    public function registerEndpointPolicy(EndpointRateLimitPolicy $policy): void
    {
        $this->endpointPolicies[$policy->pattern] = $policy;
    }

    /**
     * Escalate the global threat level (tightens limits for all clients).
     */
    public function escalateThreatLevel(ThreatLevel $level): void
    {
        $this->threatLevel = $level;
    }

    /**
     * Get the current global threat level.
     */
    public function currentThreatLevel(): ThreatLevel
    {
        return $this->threatLevel;
    }

    /**
     * Get the reputation for a specific key.
     */
    public function getReputation(string $key): ClientReputation
    {
        return $this->reputations[$key] ?? ClientReputation::default();
    }

    private function computeEffectiveLimit(string $key): int
    {
        $limit = $this->baseLimit;

        // Apply endpoint policy if one matches
        $endpoint = $this->extractEndpoint($key);
        if ($endpoint !== null && isset($this->endpointPolicies[$endpoint])) {
            $limit = $this->endpointPolicies[$endpoint]->maxAttempts;
        }

        // Apply reputation multiplier
        $reputation = $this->reputations[$key] ?? ClientReputation::default();
        $limit = (int) ((float) $limit * $reputation->multiplier());

        // Apply threat level reduction
        $limit = (int) ((float) $limit * $this->threatLevel->limitMultiplier());

        return max(1, $limit);
    }

    private function improveReputation(string $key): void
    {
        $current = $this->reputations[$key] ?? ClientReputation::default();
        $this->reputations[$key] = $current->recordSuccess();
    }

    private function degradeReputation(string $key): void
    {
        $current = $this->reputations[$key] ?? ClientReputation::default();
        $this->reputations[$key] = $current->recordViolation();
    }

    /**
     * Extract endpoint from a composite rate limit key (format: "endpoint:identifier").
     */
    private function extractEndpoint(string $key): ?string
    {
        $colonPos = strpos($key, ':');

        if ($colonPos === false) {
            return null;
        }

        return substr($key, 0, $colonPos);
    }
}
