<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Exception;

use NoDiscard;
use RuntimeException;

use function sprintf;

/**
 * Security-related MCP exceptions for access control violations.
 */
final class McpSecurityException extends RuntimeException
{
    #[NoDiscard]
    public static function toolNotAllowed(string $tool): self
    {
        return new self(sprintf('MCP tool not allowed: %s', $tool));
    }

    #[NoDiscard]
    public static function pathNotAllowed(string $path): self
    {
        return new self(sprintf('Path access not allowed: %s', $path));
    }

    #[NoDiscard]
    public static function rateLimited(string $tool, int $retryAfter): self
    {
        return new self(
            sprintf('MCP tool "%s" rate limited, retry after %d seconds', $tool, $retryAfter),
        );
    }

    #[NoDiscard]
    public static function concurrencyLimited(): self
    {
        return new self('Maximum concurrent MCP action executions reached');
    }

    #[NoDiscard]
    public static function environmentBlocked(string $mode): self
    {
        return new self(sprintf('MCP access blocked in "%s" environment', $mode));
    }
}
