<?php

declare(strict_types=1);

namespace Pulsar\AI\Streaming;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * One event from a streamed AI response.
 *
 * The constructor is private because only four shapes are meaningful and the
 * named constructors are the only way to build them: a text delta never carries
 * a finish reason, a usage delta never carries text. Consumers switch on
 * {@see $type} and read the field that type guarantees.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AiStreamDelta
{
    /**
     * @param AiStreamEventType $type Which of the four facts this delta carries
     * @param string $text Assistant text, non-empty only for {@see AiStreamEventType::Text}
     * @param ToolCallDelta|null $toolCall Tool call fragment, non-null only for {@see AiStreamEventType::ToolCall}
     * @param AiTokenUsage|null $usage Token accounting, non-null only for {@see AiStreamEventType::Usage}
     * @param string|null $finishReason Terminal reason, non-null only for {@see AiStreamEventType::Finish}
     */
    private function __construct(
        public AiStreamEventType $type,
        public string $text = '',
        public ?ToolCallDelta $toolCall = null,
        public ?AiTokenUsage $usage = null,
        public ?string $finishReason = null,
    ) {}

    /**
     * A fragment of assistant text.
     */
    #[NoDiscard]
    public static function text(string $text): self
    {
        return new self(AiStreamEventType::Text, text: $text);
    }

    /**
     * A fragment of a tool call.
     */
    #[NoDiscard]
    public static function toolCall(ToolCallDelta $fragment): self
    {
        return new self(AiStreamEventType::ToolCall, toolCall: $fragment);
    }

    /**
     * Token accounting reported by the provider.
     */
    #[NoDiscard]
    public static function usage(AiTokenUsage $usage): self
    {
        return new self(AiStreamEventType::Usage, usage: $usage);
    }

    /**
     * The provider's terminal event, carrying the same finish reason vocabulary
     * a non-streamed {@see \Pulsar\AI\AiResponse} uses.
     */
    #[NoDiscard]
    public static function finish(string $reason): self
    {
        return new self(AiStreamEventType::Finish, finishReason: $reason);
    }
}
