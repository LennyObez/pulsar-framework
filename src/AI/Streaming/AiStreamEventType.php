<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Pulsar\Api\Api;

/**
 * What a single {@see AiStreamDelta} carries.
 *
 * A stream is not a sequence of strings. Providers interleave four different
 * kinds of fact on one connection, and collapsing them into text would throw
 * away the three that budgets, audits and tool dispatch depend on.
 * @api
 */
#[Api(since: '1.0.0')]
enum AiStreamEventType: string
{
    /** A fragment of assistant text. */
    case Text = 'text';

    /** A fragment of a tool call: its identity, its name, or part of its JSON arguments. */
    case ToolCall = 'tool_call';

    /** Token accounting reported by the provider, at whatever point it reports it. */
    case Usage = 'usage';

    /** The provider's terminal event. Exactly one of these makes a stream complete. */
    case Finish = 'finish';
}
