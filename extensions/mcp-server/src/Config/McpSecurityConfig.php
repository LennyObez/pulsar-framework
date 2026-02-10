<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;

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
        /** @var list<string> $pathAllowlist */
        $pathAllowlist = (array) ($data['path_allowlist'] ?? []);

        $rateLimitPerMinute = (int) ($data['rate_limit_per_minute'] ?? 60);

        /** @var array<string, int> $toolRateLimits */
        $toolRateLimits = (array) ($data['tool_rate_limits'] ?? []);

        $maxConcurrentActions = (int) ($data['max_concurrent_actions'] ?? 1);

        return new self(
            pathAllowlist: $pathAllowlist,
            rateLimitPerMinute: $rateLimitPerMinute,
            toolRateLimits: $toolRateLimits,
            maxConcurrentActions: $maxConcurrentActions,
        );
    }
}
