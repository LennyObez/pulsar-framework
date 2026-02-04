<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\McpServer\Domain\ToolResult;

/**
 * Pipeline for redacting sensitive data from tool results before wire transmission.
 */
#[Api(since: '1.0.0')]
interface McpRedactionPipelineInterface
{
    /**
     * Redact sensitive data from a tool result.
     */
    public function redact(ToolResult $result): ToolResult;

    /**
     * Redact sensitive data from a raw string.
     */
    public function redactString(string $input): string;
}
