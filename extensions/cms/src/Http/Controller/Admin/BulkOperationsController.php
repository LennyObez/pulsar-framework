<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\PublishingStatus;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Admin controller for bulk content operations.
 *
 * Supports bulk publish, unpublish, archive, delete, tag, and untag
 * actions on multiple content items in a single request.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class BulkOperationsController
{
    use RendersAdminView;

    private const int MAX_IDS_PER_REQUEST = 100;

    private const array VALID_ACTIONS = ['publish', 'unpublish', 'archive', 'delete', 'tag', 'untag'];

    public function __construct(
        private ContentRepositoryInterface $contentRepository,
        private TaxonomyServiceInterface $taxonomyService,
        private GateInterface $gate,
        private CmsConfig $config,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    public function execute(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.content.edit');

        /** @var string $action */
        $action = $request->getAttribute('action', '');

        if (!in_array($action, self::VALID_ACTIONS, true)) {
            return Response::json(['error' => 'Invalid bulk action', 'valid_actions' => self::VALID_ACTIONS], 400);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawIds */
        $rawIds = $body['ids'] ?? [];

        if (!is_array($rawIds)) {
            return Response::json(['error' => 'ids must be an array of content IDs'], 422);
        }

        /** @var list<string> $ids */
        $ids = array_values(array_filter($rawIds, is_string(...)));

        if ($ids === []) {
            return Response::json(['error' => 'ids must contain at least one content ID'], 422);
        }

        if (count($ids) > self::MAX_IDS_PER_REQUEST) {
            return Response::json([
                'error' => 'Maximum ' . self::MAX_IDS_PER_REQUEST . ' IDs per request',
            ], 422);
        }

        $tenantId = $this->validateTenantAccess($request);

        $affected = match ($action) {
            'publish' => $this->contentRepository->bulkUpdateStatus($ids, PublishingStatus::Published, $tenantId),
            'unpublish' => $this->contentRepository->bulkUpdateStatus($ids, PublishingStatus::Draft, $tenantId),
            'archive' => $this->contentRepository->bulkUpdateStatus($ids, PublishingStatus::Archived, $tenantId),
            'delete' => $this->contentRepository->bulkDelete($ids, $tenantId),
            'tag' => $this->executeBulkTag($ids, $body),
            'untag' => $this->executeBulkUntag($ids, $body),
        };

        if ($affected === -1) {
            return Response::json(['error' => 'term_id is required for tag/untag actions'], 422);
        }

        return Response::json(['affected' => $affected, 'action' => $action]);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $body
     */
    private function executeBulkTag(array $ids, array $body): int
    {
        $termId = $body['term_id'] ?? null;

        if (!is_string($termId) || $termId === '') {
            return -1;
        }

        $this->taxonomyService->bulkTag($ids, $termId);

        return count($ids);
    }

    /**
     * @param list<string> $ids
     * @param array<string, mixed> $body
     */
    private function executeBulkUntag(array $ids, array $body): int
    {
        $termId = $body['term_id'] ?? null;

        if (!is_string($termId) || $termId === '') {
            return -1;
        }

        $this->taxonomyService->bulkUntag($ids, $termId);

        return count($ids);
    }
}
