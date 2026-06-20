<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Config;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

use function is_array;

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
        $toolRateLimits = $data['tool_rate_limits'] ?? null;
        /** @var array<string, int> $toolRateLimitsMap */
        $toolRateLimitsMap = is_array($toolRateLimits) ? $toolRateLimits : [];

        return new self(
            pathAllowlist: Coerce::listOfString($data['path_allowlist'] ?? null),
            rateLimitPerMinute: Coerce::strictInt($data['rate_limit_per_minute'] ?? null, 60),
            toolRateLimits: $toolRateLimitsMap,
            maxConcurrentActions: Coerce::strictInt($data['max_concurrent_actions'] ?? null, 1),
        );
    }
}
