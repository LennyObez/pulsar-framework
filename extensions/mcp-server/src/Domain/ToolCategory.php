<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Domain;

use Pulsar\Api\Api;

/**
 * Classification of MCP tools by their side-effect profile.
 *
 * Read tools are safe to execute without explicit user approval.
 * Action tools may modify state and require permission checks.
 */
#[Api(since: '1.0.0')]
enum ToolCategory: string
{
    case Read = 'read';
    case Action = 'action';
}
