<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Immutable result of an MCP tool execution.
 *
 * Carries both structured data (for programmatic consumption) and
 * a text fallback (for display in clients that don't parse structured output).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ToolResult
{
    /**
     * @param array<string, mixed> $structuredContent Machine-readable result data
     * @param string $textContent Human-readable text fallback
     * @param bool $isError Whether the tool execution failed
     * @param array<string, mixed> $meta Additional metadata (timing, truncation info, etc.)
     */
    public function __construct(
        public array $structuredContent,
        public string $textContent,
        public bool $isError,
        public array $meta = [],
    ) {}

    /**
     * Create a successful tool result.
     *
     * @param array<string, mixed> $structuredContent Structured result data
     * @param string $textContent Human-readable summary
     * @param array<string, mixed> $meta Optional metadata
     */
    #[NoDiscard]
    public static function success(array $structuredContent, string $textContent, array $meta = []): self
    {
        return new self(
            structuredContent: $structuredContent,
            textContent: $textContent,
            isError: false,
            meta: $meta,
        );
    }

    /**
     * Create an error tool result.
     *
     * @param string $message Error description
     * @param array<string, mixed> $meta Optional metadata
     */
    #[NoDiscard]
    public static function error(string $message, array $meta = []): self
    {
        return new self(
            structuredContent: ['error' => $message],
            textContent: $message,
            isError: true,
            meta: $meta,
        );
    }

    /**
     * Create a result indicating the output was truncated.
     *
     * @param array<string, mixed> $structuredContent Truncated structured data
     * @param string $textContent Truncated text
     * @param int $originalBytes Original output size before truncation
     * @param int $truncatedBytes Size after truncation
     */
    #[NoDiscard]
    public static function truncated(
        array $structuredContent,
        string $textContent,
        int $originalBytes,
        int $truncatedBytes,
    ): self {
        return new self(
            structuredContent: $structuredContent,
            textContent: $textContent,
            isError: false,
            meta: [
                'truncated' => true,
                'original_bytes' => $originalBytes,
                'truncated_bytes' => $truncatedBytes,
            ],
        );
    }
}
