<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A message in a ticket conversation thread.
 *
 * Messages can be public (visible to the reporter) or internal
 * (admin-only notes visible only to agents).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TicketMessage
{
    /**
     * @param string $id UUIDv7
     * @param string $ticketId FK to Ticket
     * @param string|null $authorId FK to user (null for system messages)
     * @param string $authorName Display name of the author
     * @param string $body Message body (plain text or markdown)
     * @param bool $isInternal Whether this is an admin-only internal note
     * @param list<string> $attachments List of attachment file references
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public string $ticketId,
        public ?string $authorId,
        public string $authorName,
        public string $body,
        public bool $isInternal,
        public array $attachments,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new public message.
     *
     * @param list<string> $attachments
     */
    public static function create(
        string $id,
        string $ticketId,
        ?string $authorId,
        string $authorName,
        string $body,
        array $attachments = [],
    ): self {
        return new self(
            id: $id,
            ticketId: $ticketId,
            authorId: $authorId,
            authorName: $authorName,
            body: $body,
            isInternal: false,
            attachments: $attachments,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Create an internal note (admin-only).
     *
     * @param list<string> $attachments
     */
    public static function createInternal(
        string $id,
        string $ticketId,
        string $authorId,
        string $authorName,
        string $body,
        array $attachments = [],
    ): self {
        return new self(
            id: $id,
            ticketId: $ticketId,
            authorId: $authorId,
            authorName: $authorName,
            body: $body,
            isInternal: true,
            attachments: $attachments,
            createdAt: new DateTimeImmutable(),
        );
    }
}
