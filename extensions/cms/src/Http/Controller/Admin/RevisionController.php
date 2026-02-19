<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentTranslation;
use Pulsar\Extension\Cms\Content\ContentTranslationRepositoryInterface;
use Pulsar\Extension\Cms\Content\SafeHtmlPolicy;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;

/**
 * Admin controller for content revision history and restoration.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class RevisionController
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentRevisionRepositoryInterface $revisionRepository,
        private ContentTranslationRepositoryInterface $translationRepository,
        private SafeHtmlPolicy $safeHtmlPolicy,
        private GateInterface $gate,
        private CmsConfig $config,
    ) {}

    public function index(ServerRequestInterface $request, string $contentId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.view');

        $content = $this->contentRepository->findById($contentId);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        $locale = $this->resolveLocale($request);
        $revisions = $this->revisionRepository->findByContentAndLocale($contentId, $locale);

        return Response::json([
            'content_id' => $contentId,
            'locale' => $locale,
            'revisions' => array_map(static fn(ContentRevision $r) => [
                'id' => $r->id,
                'revision_number' => $r->revisionNumber,
                'title' => $r->title,
                'author_id' => $r->authorId,
                'reason' => $r->reason,
                'evidence_hash' => $r->evidenceHash,
                'created_at' => $r->createdAt->format('c'),
            ], $revisions),
        ]);
    }

    public function restore(ServerRequestInterface $request, string $contentId, string $revisionId): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.restore');

        $content = $this->contentRepository->findById($contentId);

        if ($content === null) {
            return Response::json(['error' => 'Content not found'], 404);
        }

        $revision = $this->revisionRepository->findById($revisionId);

        if ($revision === null || $revision->contentId !== $contentId) {
            return Response::json(['error' => 'Revision not found'], 404);
        }

        // Find the current translation for the revision's locale
        $translation = $this->translationRepository->findByContentAndLocale($contentId, $revision->locale);

        if ($translation === null) {
            return Response::json(['error' => 'Translation not found for revision locale'], 404);
        }

        // Restore the translation to the revision's state
        $restoredTranslation = ContentTranslation::create(
            id: $translation->id,
            contentId: $contentId,
            locale: $revision->locale,
            title: $revision->title,
            slugSegment: $revision->slug,
            path: $translation->path,
            body: $this->safeHtmlPolicy->sanitize($revision->body),
            excerpt: $revision->excerpt,
            metaTitle: $revision->metaTitle,
            metaDescription: $revision->metaDescription,
        );

        $this->translationRepository->save($restoredTranslation);

        return Response::json([
            'content_id' => $contentId,
            'revision_id' => $revisionId,
            'locale' => $revision->locale,
            'status' => 'restored',
        ]);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }
}
