<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Forms\FormSubmission;
use Pulsar\Extension\Cms\Forms\FormSubmissionRepositoryInterface;
use Pulsar\Extension\Cms\Forms\FormSubmissionServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;
use RuntimeException;

use function array_map;
use function count;
use function is_array;
use function is_int;
use function is_scalar;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for form submission management.
 *
 * Provides listing, detail view, moderation (read/spam), export,
 * and bulk delete operations.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class FormSubmissionController extends AbstractAdminController
{
    public function __construct(
        private FormSubmissionRepositoryInterface $repository,
        private FormSubmissionServiceInterface $formService,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * List form submissions with filtering.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.forms.view');

        $tenantId = $this->validateTenantAccess($request);
        $params = $request->getQueryParams();

        /** @var mixed $rawFilter */
        $rawFilter = $params['filter'] ?? null;
        $filter = is_string($rawFilter) ? $rawFilter : 'all';
        /** @var mixed $rawPage */
        $rawPage = $params['page'] ?? null;
        $page = max(1, is_int($rawPage) ? $rawPage : 1);
        /** @var mixed $rawPerPage */
        $rawPerPage = $params['per_page'] ?? null;
        $perPage = min(100, max(1, is_int($rawPerPage) ? $rawPerPage : 20));
        $offset = ($page - 1) * $perPage;

        // Push all filtering to the repository query instead of fetching then filtering in PHP
        $includeSpam = $filter === 'spam' || $filter === 'all';
        $unreadOnly = $filter === 'unread' ? true : null;

        $submissions = $this->repository->findAll($tenantId, $includeSpam, $perPage, $offset, $unreadOnly);

        $unreadCount = $this->repository->countUnread($tenantId);

        $data = [
            'submissions' => array_map(static fn(FormSubmission $s): array => [
                'id' => $s->id,
                'form_block_id' => $s->formBlockId,
                'content_id' => $s->contentId,
                'submitted_at' => $s->submittedAt->format('c'),
                'is_read' => $s->isRead,
                'is_spam' => $s->isSpam,
                'spam_score' => $s->spamScore,
                'data_preview' => self::dataPreview($s->data),
            ], $submissions),
            'filter' => $filter,
            'unread_count' => $unreadCount,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ];

        return $this->respondWithView($request, 'admin.forms.index', $data);
    }

    /**
     * Show a single form submission.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.forms.view');

        $tenantId = $this->validateTenantAccess($request);
        $submission = $this->repository->findById($id, $tenantId);

        if ($submission === null) {
            return Response::json(['error' => 'Form submission not found'], 404);
        }

        // Auto-mark as read when viewed
        if (!$submission->isRead) {
            $this->repository->markAsRead($id);
        }

        $data = [
            'submission' => [
                'id' => $submission->id,
                'form_block_id' => $submission->formBlockId,
                'content_id' => $submission->contentId,
                'tenant_id' => $submission->tenantId,
                'data' => $submission->data,
                'ip_hash' => $submission->ipHash,
                'user_agent_hash' => $submission->userAgentHash,
                'submitted_at' => $submission->submittedAt->format('c'),
                'evidence_hash' => $submission->evidenceHash,
                'is_read' => true,
                'is_spam' => $submission->isSpam,
                'spam_score' => $submission->spamScore,
                'spam_reason' => $submission->spamReason,
            ],
        ];

        return $this->respondWithView($request, 'admin.forms.show', $data);
    }

    /**
     * Mark a submission as read.
     */
    public function markAsRead(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.forms.manage');

        $tenantId = $this->validateTenantAccess($request);

        try {
            $this->formService->markAsRead($id, $tenantId);

            return Response::json(['id' => $id, 'is_read' => true]);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Mark a submission as spam.
     */
    public function markAsSpam(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.forms.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var mixed $rawReason */
        $rawReason = $body['reason'] ?? null;
        $reason = is_string($rawReason) ? $rawReason : 'Manually marked as spam';

        $tenantId = $this->validateTenantAccess($request);

        try {
            $this->formService->markAsSpam($id, $reason, $tenantId);

            return Response::json(['id' => $id, 'is_spam' => true]);
        } catch (RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * Export submissions for a content ID as CSV.
     */
    public function export(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.forms.view');

        $tenantId = $this->validateTenantAccess($request);
        $params = $request->getQueryParams();
        /** @var mixed $rawContentId */
        $rawContentId = $params['content_id'] ?? null;
        $contentId = is_string($rawContentId) ? $rawContentId : '';

        if ($contentId === '') {
            return Response::json(['error' => 'content_id query parameter is required'], 400);
        }

        $csv = $this->formService->exportSubmissions($contentId, $tenantId);

        return new Response(
            headers: [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="form-submissions.csv"',
            ],
            body: $csv,
        );
    }

    /**
     * Bulk delete submissions.
     *
     * Each submission is verified to belong to the current tenant before deletion,
     * preventing cross-tenant data access.
     */
    public function bulkDelete(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.forms.manage');

        $tenantId = $this->validateTenantAccess($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawIdsRaw */
        $rawIdsRaw = $body['ids'] ?? null;
        $rawIds = is_array($rawIdsRaw) ? $rawIdsRaw : [];

        $deleted = 0;

        /** @var mixed $id */
        foreach ($rawIds as $id) {
            if (!is_string($id)) {
                continue;
            }

            // Verify the submission belongs to this tenant before deleting
            $submission = $this->repository->findById($id, $tenantId);

            if ($submission !== null) {
                $this->repository->delete($id);
                $deleted++;
            }
        }

        return Response::json(['deleted' => $deleted]);
    }

    /**
     * Create a short preview of form data for the list view.
     *
     * @param array<string, mixed> $data
     */
    private static function dataPreview(array $data): string
    {
        $parts = [];

        /** @var mixed $value */
        foreach ($data as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            $parts[] = $key . ': ' . (is_string($value) ? mb_strimwidth($value, 0, 50, '...') : (is_scalar($value) ? (string) $value : ''));

            if (count($parts) >= 3) {
                break;
            }
        }

        return implode(' | ', $parts);
    }
}
