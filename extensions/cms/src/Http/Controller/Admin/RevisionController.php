<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRevision;
use Pulsar\Extension\Cms\Content\ContentRevisionRepositoryInterface;
use Pulsar\Extension\Cms\Content\FieldDiff;
use Pulsar\Extension\Cms\Content\RevisionService;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;

/**
 * Admin controller for content revision history, diff, and restoration.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class RevisionController extends AbstractAdminController
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private ContentRevisionRepositoryInterface $revisionRepository,
        private RevisionService $revisionService,
        private CmsConfig $config,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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

        $data = [
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
        ];

        return $this->respondWithView($request, 'admin.revisions.index', $data);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function diff(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.view');

        $params = $request->getQueryParams();
        $fromId = $params['from'] ?? null;
        $toId = $params['to'] ?? null;

        if (!is_string($fromId) || !is_string($toId)) {
            return Response::json(['error' => 'Both "from" and "to" revision IDs are required'], 400);
        }

        $diff = $this->revisionService->computeDiff($fromId, $toId);

        return Response::json([
            'from_revision_id' => $diff->fromRevisionId,
            'to_revision_id' => $diff->toRevisionId,
            'changes' => array_map(static fn(FieldDiff $change) => [
                'field' => $change->field,
                'from' => $change->from,
                'to' => $change->to,
            ], $diff->changes),
        ]);
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

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

        $this->revisionService->restoreRevision(
            $revisionId,
            $identity->id(),
            'Restored to revision #' . $revision->revisionNumber,
        );

        return Response::json([
            'content_id' => $contentId,
            'revision_id' => $revisionId,
            'locale' => $revision->locale,
            'status' => 'restored',
        ]);
    }

    private function resolveLocale(ServerRequestInterface $request): string
    {
        /** @var mixed $locale */
        $locale = $request->getQueryParams()['locale'] ?? null;

        return is_string($locale) ? $locale : $this->config->defaultLocale;
    }
}
