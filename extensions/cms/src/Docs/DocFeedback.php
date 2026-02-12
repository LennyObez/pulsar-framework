<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Docs;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Represents user feedback on a documentation page.
 *
 * Captures whether a visitor found a doc page helpful, with an
 * optional free-text comment for qualitative feedback.
 */
#[Api(since: '1.0.0')]
final readonly class DocFeedback
{
    /**
     * @param string $id UUIDv7
     * @param string $docPageId Content ID of the doc_page
     * @param string|null $userId FK to auth_users for authenticated feedback
     * @param bool $isHelpful Whether the user found the page helpful
     * @param string|null $comment Optional free-text comment
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     */
    public function __construct(
        public string $id,
        public string $docPageId,
        public ?string $userId,
        public bool $isHelpful,
        public ?string $comment,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a new feedback entry with the current timestamp.
     */
    public static function create(
        string $id,
        string $docPageId,
        ?string $userId,
        bool $isHelpful,
        ?string $comment,
    ): self {
        return new self($id, $docPageId, $userId, $isHelpful, $comment, new DateTimeImmutable());
    }
}
