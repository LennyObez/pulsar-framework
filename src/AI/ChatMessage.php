<?php

declare(strict_types=1);

namespace Pulsar\AI;

use Pulsar\Api\Api;

/**
 * Represents a single message in a chat conversation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ChatMessage
{
    /**
     * @param ChatRole $role The role of the message sender
     * @param string $content The message content
     * @param string|null $name Optional name for the participant
     * @param string|null $toolCallId For tool responses, the ID of the call being answered
     */
    public function __construct(
        public ChatRole $role,
        public string $content,
        public ?string $name = null,
        public ?string $toolCallId = null,
    ) {}

    /**
     * Create a system message.
     */
    public static function system(string $content): self
    {
        return new self(ChatRole::System, $content);
    }

    /**
     * Create a user message.
     */
    public static function user(string $content): self
    {
        return new self(ChatRole::User, $content);
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(string $content): self
    {
        return new self(ChatRole::Assistant, $content);
    }

    /**
     * Create a tool result message.
     */
    public static function toolResult(string $toolCallId, string $content): self
    {
        return new self(ChatRole::Tool, $content, toolCallId: $toolCallId);
    }
}
