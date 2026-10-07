<?php

declare(strict_types=1);

namespace Pulsar\AI;

use Pulsar\Api\Api;

/**
 * Roles in a chat conversation.
 * @api
 */
#[Api(since: '1.0.0')]
enum ChatRole: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
