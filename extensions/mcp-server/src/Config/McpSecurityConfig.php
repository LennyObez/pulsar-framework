<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;

use function array_filter;
use function array_values;

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
     * @param array{
     *     path_allowlist?: list<string>,
     *     rate_limit_per_minute?: int,
     *     tool_rate_limits?: array<string, int>,
     *     max_concurrent_actions?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            pathAllowlist: array_values(array_filter($data['path_allowlist'] ?? [], '\is_string')),
            rateLimitPerMinute: $data['rate_limit_per_minute'] ?? 60,
            toolRateLimits: $data['tool_rate_limits'] ?? [],
            maxConcurrentActions: $data['max_concurrent_actions'] ?? 1,
        );
    }
}
