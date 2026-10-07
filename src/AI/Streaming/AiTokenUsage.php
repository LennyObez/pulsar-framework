<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Token accounting as a provider reported it at one point in a stream.
 *
 * Both fields are nullable because providers report the two halves at different
 * moments: Anthropic sends input tokens with the opening event and output tokens
 * with the closing one, OpenAI sends both in a trailing usage chunk, Ollama sends
 * both on the final object. A null means "this event said nothing about that
 * number", which is not the same fact as zero — and only the event that measured
 * a number may claim it.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiTokenUsage
{
    /**
     * @param int|null $inputTokens Prompt tokens, or null when this event did not report them
     * @param int|null $outputTokens Completion tokens, or null when this event did not report them
     */
    public function __construct(
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
    ) {}

    /**
     * Whether this event reported any number at all.
     */
    #[NoDiscard]
    public function isEmpty(): bool
    {
        return $this->inputTokens === null && $this->outputTokens === null;
    }
}
