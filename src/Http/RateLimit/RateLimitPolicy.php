<?php

declare(strict_types=1);

namespace Pulsar\Http\RateLimit;

use Pulsar\Api\Api;

use function count;
use function preg_match;

/**
 * Policy engine for per-route/group rate limit overrides.
 *
 * Maps route patterns to rate limit tiers. Supports different limits
 * by authentication state or API key tier. Patterns are evaluated in
 * registration order; the first match wins.
 * @api
 */
#[Api(since: '1.0.0')]
final class RateLimitPolicy
{
    /** @var list<array{pattern: string, tier: string, limits: RateLimitTier}> */
    private array $rules = [];

    private ?RateLimitTier $default = null;

    /**
     * Set the default rate limit applied when no rule matches.
     */
    public function setDefault(RateLimitTier $tier): void
    {
        $this->default = $tier;
    }

    /**
     * Add a rate limit rule for a route pattern and tier.
     *
     * @param string        $routePattern Regex pattern to match against the request path
     * @param string        $tier         Tier name (e.g., "anonymous", "authenticated", "premium")
     * @param RateLimitTier $limits       Rate limit configuration for this combination
     */
    public function addRule(string $routePattern, string $tier, RateLimitTier $limits): void
    {
        $this->rules[] = ['pattern' => $routePattern, 'tier' => $tier, 'limits' => $limits];
    }

    /**
     * Resolve the applicable rate limit for a given path and tier.
     *
     * Returns the first matching rule, or the default if no rule matches.
     * Returns null if no rule matches and no default is configured.
     */
    public function resolve(string $path, string $tier): ?RateLimitTier
    {
        foreach ($this->rules as $rule) {
            if ($rule['tier'] !== $tier) {
                continue;
            }

            if (preg_match($rule['pattern'], $path) === 1) {
                return $rule['limits'];
            }
        }

        return $this->default;
    }

    /**
     * Get the number of configured rules.
     */
    public function ruleCount(): int
    {
        return count($this->rules);
    }

    /**
     * Get the default tier, if configured.
     */
    public function defaultTier(): ?RateLimitTier
    {
        return $this->default;
    }
}
