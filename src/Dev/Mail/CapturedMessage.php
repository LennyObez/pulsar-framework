<?php

declare(strict_types=1);

namespace Pulsar\Dev\Mail;

use Pulsar\Api\Internal;
use Pulsar\Mail\Message;

use function count;

/**
 * A captured email message with metadata for the preview UI.
 */
#[Internal]
final readonly class CapturedMessage
{
    /**
     * @param string $id Unique message identifier
     * @param Message $message The original mail message
     * @param float $capturedAt Unix timestamp when the message was captured
     */
    public function __construct(
        public string $id,
        public Message $message,
        public float $capturedAt,
    ) {}

    /**
     * Serialize to an array for API/UI display.
     *
     * @return array{id: string, from: string, to: list<string>, subject: string, has_html: bool, has_text: bool, attachment_count: int, captured_at: float}
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->message->from->email,
            'to' => array_map(
                static fn(\Pulsar\Mail\Address $addr): string => $addr->email,
                $this->message->to,
            ),
            'subject' => $this->message->subject,
            'has_html' => $this->message->htmlBody !== null,
            'has_text' => $this->message->textBody !== null,
            'attachment_count' => count($this->message->attachments),
            'captured_at' => $this->capturedAt,
        ];
    }
}
