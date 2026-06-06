<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Exception;

use NoDiscard;
use RuntimeException;

use function sprintf;

/**
 * MCP protocol and tool execution exceptions.
 */
final class McpException extends RuntimeException
{
    #[NoDiscard]
    public static function toolNotFound(string $name): self
    {
        return new self(sprintf('MCP tool not found: %s', $name));
    }

    #[NoDiscard]
    public static function protocolError(string $message, int $code): self
    {
        return new self(sprintf('MCP protocol error (%d): %s', $code, $message), $code);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    #[NoDiscard]
    public static function executionFailed(string $tool, string $reason): self
    {
        return new self(sprintf('MCP tool "%s" execution failed: %s', $tool, $reason));
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    #[NoDiscard]
    public static function outputTruncated(string $tool): self
    {
        return new self(sprintf('MCP tool "%s" output exceeded maximum size and was truncated', $tool));
    }
}
