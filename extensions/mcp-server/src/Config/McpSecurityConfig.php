<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function is_array;
use function is_int;

/**
 * Security sub-configuration for MCP access control.
 */
#[Internal]
final readonly class McpSecurityConfig
{
    /**
     * @param list<string> $pathAllowlist Allowed filesystem paths for tool access
     * @param int $rateLimitPerMinute Global rate limit for tool calls
     * @param array<string, int> $toolRateLimits Per-tool rate limit overrides
     * @param int $maxConcurrentActions Maximum simultaneous action tool executions
     */
    public function __construct(
        public array $pathAllowlist,
        public int $rateLimitPerMinute,
        public array $toolRateLimits,
        public int $maxConcurrentActions,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $pathAllowlistRaw = $data['path_allowlist'] ?? [];
        $pathAllowlist = is_array($pathAllowlistRaw) ? array_values(array_filter($pathAllowlistRaw, '\is_string')) : [];

        $rateLimitRaw = $data['rate_limit_per_minute'] ?? null;
        $rateLimitPerMinute = is_int($rateLimitRaw) ? $rateLimitRaw : 60;

        $toolRateLimitsRaw = $data['tool_rate_limits'] ?? [];
        /** @var array<string, int> $toolRateLimits */
        $toolRateLimits = is_array($toolRateLimitsRaw) ? $toolRateLimitsRaw : [];

        $maxConcurrentRaw = $data['max_concurrent_actions'] ?? null;
        $maxConcurrentActions = is_int($maxConcurrentRaw) ? $maxConcurrentRaw : 1;

        return new self(
            pathAllowlist: $pathAllowlist,
            rateLimitPerMinute: $rateLimitPerMinute,
            toolRateLimits: $toolRateLimits,
            maxConcurrentActions: $maxConcurrentActions,
        );
    }
}
