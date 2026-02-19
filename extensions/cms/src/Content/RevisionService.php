<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Content;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Exception\CmsException;

use function sodium_crypto_generichash;
use function sprintf;

/**
 * Manages content revision lifecycle: creation, retrieval, and restoration.
 *
 * Each revision captures a point-in-time snapshot of a content translation,
 * with an evidence hash (BLAKE2b) proving data integrity.
 */
#[Internal]
final readonly class RevisionService
{
    public function __construct(
        private ContentRevisionRepositoryInterface $revisionRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
    ) {}

    /**
     * Create a new revision capturing the current state of a translation.
     *
     * @param string $contentId UUIDv7
     * @param string $locale BCP 47 locale code
     * @param string $authorId UUIDv7 of the revision author
     * @param string|null $reason Optional reason for the revision
     * @param string $title Translation title
     * @param string $slug Translation slug segment
     * @param string $body Translation body
     * @param string|null $excerpt Translation excerpt
     * @param string|null $metaTitle Translation meta title
     * @param string|null $metaDescription Translation meta description
     */
    public function createRevision(
        string $contentId,
        string $locale,
        string $authorId,
        ?string $reason,
        string $title,
        string $slug,
        string $body,
        ?string $excerpt,
        ?string $metaTitle,
        ?string $metaDescription,
    ): ContentRevision {
        $revisionNumber = $this->revisionRepository->getLatestRevisionNumber($contentId, $locale) + 1;

        $evidenceHash = $this->computeEvidenceHash($title, $slug, $body, $excerpt, $metaTitle, $metaDescription);

        $revision = new ContentRevision(
            id: $this->generateId(),
            contentId: $contentId,
            locale: $locale,
            revisionNumber: $revisionNumber,
            title: $title,
            slug: $slug,
            body: $body,
            excerpt: $excerpt,
            metaTitle: $metaTitle,
            metaDescription: $metaDescription,
            authorId: $authorId,
            reason: $reason,
            evidenceHash: $evidenceHash,
            createdAt: new DateTimeImmutable(),
        );

        $this->revisionRepository->save($revision);

        return $revision;
    }

    /**
     * Create a revision from an existing ContentTranslation entity.
     */
    public function createRevisionFromTranslation(
        ContentTranslation $translation,
        string $authorId,
        ?string $reason = null,
    ): ContentRevision {
        return $this->createRevision(
            contentId: $translation->contentId,
            locale: $translation->locale,
            authorId: $authorId,
            reason: $reason,
            title: $translation->title,
            slug: $translation->slugSegment,
            body: $translation->body,
            excerpt: $translation->excerpt,
            metaTitle: $translation->metaTitle,
            metaDescription: $translation->metaDescription,
        );
    }

    /**
     * Get all revisions for a content item in a locale.
     *
     * @return list<ContentRevision>
     */
    public function getRevisions(string $contentId, string $locale): array
    {
        return $this->revisionRepository->findByContentAndLocale($contentId, $locale);
    }

    /**
     * Restore a content translation to the state captured in a revision.
     *
     * Creates a new revision capturing the current state before overwriting,
     * then updates the translation with the revision's data.
     *
     * @throws CmsException If the revision or translation is not found
     */
    public function restoreRevision(
        string $revisionId,
        string $authorId,
        string $reason,
    ): ContentTranslation {
        $revision = $this->revisionRepository->findById($revisionId);

        if ($revision === null) {
            throw CmsException::contentNotFound($revisionId);
        }

        $translation = $this->translationRepository->findByContentAndLocale(
            $revision->contentId,
            $revision->locale,
        );

        if ($translation === null) {
            throw CmsException::translationNotFound($revision->contentId, $revision->locale);
        }

        // Capture current state as a new revision before restoring
        $this->createRevisionFromTranslation(
            $translation,
            $authorId,
            'Auto-captured before restore to revision #' . $revision->revisionNumber,
        );

        // Build the restored translation
        $restored = new ContentTranslation(
            id: $translation->id,
            contentId: $translation->contentId,
            locale: $translation->locale,
            title: $revision->title,
            slugSegment: $revision->slug,
            path: $translation->path,
            body: $revision->body,
            excerpt: $revision->excerpt,
            metaTitle: $revision->metaTitle,
            metaDescription: $revision->metaDescription,
            ogImageId: $translation->ogImageId,
            robots: $translation->robots,
            structuredDataOverrides: $translation->structuredDataOverrides,
            readingTimeMinutes: $translation->readingTimeMinutes,
            bodyPlaintext: $translation->bodyPlaintext,
            headingsText: $translation->headingsText,
            customFieldsText: $translation->customFieldsText,
            taxonomyTermsText: $translation->taxonomyTermsText,
        );

        $this->translationRepository->save($restored);

        // Create a new revision recording the restoration
        $this->createRevision(
            contentId: $revision->contentId,
            locale: $revision->locale,
            authorId: $authorId,
            reason: $reason,
            title: $revision->title,
            slug: $revision->slug,
            body: $revision->body,
            excerpt: $revision->excerpt,
            metaTitle: $revision->metaTitle,
            metaDescription: $revision->metaDescription,
        );

        return $restored;
    }

    /**
     * Compute BLAKE2b evidence hash for revision integrity verification.
     */
    private function computeEvidenceHash(
        string $title,
        string $slug,
        string $body,
        ?string $excerpt,
        ?string $metaTitle,
        ?string $metaDescription,
    ): string {
        $payload = $title . $slug . $body . ($excerpt ?? '') . ($metaTitle ?? '') . ($metaDescription ?? '');

        return bin2hex(sodium_crypto_generichash($payload));
    }

    private function generateId(): string
    {
        // UUIDv7 generation: timestamp-ordered with random suffix
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12) . bin2hex(random_bytes(1)),
        );
    }
}
