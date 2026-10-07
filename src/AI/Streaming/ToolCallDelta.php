<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use Pulsar\Api\Api;

/**
 * One fragment of a tool call arriving over a stream.
 *
 * No provider delivers a tool call whole. Anthropic announces the identity and
 * name first and then streams the arguments as partial JSON; OpenAI does the
 * same over indexed `tool_calls` entries; Ollama emits the arguments as one
 * finished object. All three reduce to this: an index that groups the fragments,
 * an identity and a name that arrive once, and argument text that arrives in
 * pieces. Ollama's single-shot arguments are simply one fragment containing the
 * whole JSON object, so the accumulation rule is the same for every provider.
 *
 * The arguments stay text until the stream ends. Decoding a prefix of a JSON
 * object is how a tool ends up invoked with half its parameters.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ToolCallDelta
{
    /**
     * @param int $index Groups fragments belonging to the same call, and orders the calls
     * @param string|null $id Provider-assigned call id, when this fragment carried one
     * @param string|null $name Tool name, when this fragment carried one
     * @param string $argumentsFragment Raw JSON text to append to this call's arguments
     */
    public function __construct(
        public int $index,
        public ?string $id = null,
        public ?string $name = null,
        public string $argumentsFragment = '',
    ) {}
}
