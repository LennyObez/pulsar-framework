<?php

declare(strict_types=1);

namespace Pulsar\AI\Audit;

use Pulsar\Api\Api;

/**
 * Which call on {@see \Pulsar\AI\AiClientInterface} produced an inference record.
 *
 * Recorded as a field of its own rather than folded into the action string,
 * because the five operations carry different risk and an assessor filters on
 * that: `embed` sends content to a third party and returns no completion,
 * `structured_output` constrains a model's answer into a schema an application
 * then acts on, and `stream_chat` is the only one whose token accounting is not
 * final until the connection closes.
 * @api
 */
#[Api(since: '1.0.0')]
enum InferenceOperation: string
{
    /** {@see \Pulsar\AI\AiClientInterface::chat()} — a multi-turn conversation. */
    case Chat = 'chat';

    /** {@see \Pulsar\AI\AiClientInterface::streamChat()} — a conversation read as it is generated. */
    case StreamChat = 'stream_chat';

    /** {@see \Pulsar\AI\AiClientInterface::complete()} — a single prompt. */
    case Complete = 'complete';

    /** {@see \Pulsar\AI\AiClientInterface::structuredOutput()} — a completion constrained to a JSON schema. */
    case StructuredOutput = 'structured_output';

    /** {@see \Pulsar\AI\AiClientInterface::embed()} — texts sent out to be vectorised. */
    case Embed = 'embed';
}
