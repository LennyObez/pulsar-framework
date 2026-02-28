<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Api;

use function sodium_crypto_generichash;

use const SODIUM_CRYPTO_GENERICHASH_BYTES;

/**
 * Immutable snapshot of a content translation at a point in time.
 *
 * Every status transition creates a revision. The evidence hash proves
 * the exact state of the translation at the moment of the transition.
 *
 * @psalm-api Public DTO returned from ContentRevisionRepositoryInterface;
 *            consumed by RevisionService and admin revision history views.
 */
#[Api(since: '1.0.0')]
final readonly class ContentRevision
{
    /**
     * @param string $id UUIDv7
     * @param string $contentId UUIDv7 FK content
     * @param string $locale BCP 47 locale code
     * @param int $revisionNumber Auto-increment per (content_id, locale)
     * @param string $title Snapshot of title
     * @param string $slug Snapshot of slug
     * @param string $body Snapshot of body
     * @param string|null $excerpt Snapshot of excerpt
     * @param string|null $metaTitle Snapshot of meta title
     * @param string|null $metaDescription Snapshot of meta description
     * @param string $authorId UUIDv7 of who made this revision
     * @param string|null $reason Optional change reason
     * @param string $evidenceHash BLAKE2b hash of all snapshot fields
     * @param DateTimeImmutable $createdAt Immutable timestamp
     */
    public function __construct(
        public string $id,
        public string $contentId,
        public string $locale,
        public int $revisionNumber,
        public string $title,
        public string $slug,
        public string $body,
        public ?string $excerpt,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public string $authorId,
        public ?string $reason,
        public string $evidenceHash,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * Create a revision from a content translation snapshot.
     */
    public static function fromTranslation(
        string $id,
        ContentTranslation $translation,
        int $revisionNumber,
        string $authorId,
        ?string $reason = null,
    ): self {
        $evidenceHash = self::computeEvidenceHash(
            $translation->title,
            $translation->slugSegment,
            $translation->body,
            $translation->excerpt,
            $translation->metaTitle,
            $translation->metaDescription,
        );

        return new self(
            id: $id,
            contentId: $translation->contentId,
            locale: $translation->locale,
            revisionNumber: $revisionNumber,
            title: $translation->title,
            slug: $translation->slugSegment,
            body: $translation->body,
            excerpt: $translation->excerpt,
            metaTitle: $translation->metaTitle,
            metaDescription: $translation->metaDescription,
            authorId: $authorId,
            reason: $reason,
            evidenceHash: $evidenceHash,
            createdAt: new DateTimeImmutable(),
        );
    }

    /**
     * Compute the BLAKE2b evidence hash of all snapshot fields.
     */
    public static function computeEvidenceHash(
        string $title,
        string $slug,
        string $body,
        ?string $excerpt,
        ?string $metaTitle,
        ?string $metaDescription,
    ): string {
        $payload = implode("\0", [
            $title,
            $slug,
            $body,
            $excerpt ?? '',
            $metaTitle ?? '',
            $metaDescription ?? '',
        ]);

        return bin2hex(sodium_crypto_generichash($payload, '', SODIUM_CRYPTO_GENERICHASH_BYTES));
    }
}
