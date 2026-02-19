<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;
use function strlen;

/**
 * Admin controller for CMS administrative tools.
 *
 * Provides GDPR data export and PII erasure endpoints.
 * Export requires cms.tools.export; erasure requires cms.tools.gdpr.erase.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class ToolsController extends AbstractAdminController
{
    public function __construct(
        private ToolsServiceInterface $toolsService,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * Export all CMS data for a given user (GDPR data portability).
     *
     * Requires step-up authentication.
     */
    public function exportUserData(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.export');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $userId = is_string($body['user_id'] ?? null) ? $body['user_id'] : '';

        if ($userId === '') {
            return Response::json(['error' => 'user_id is required'], 400);
        }

        $data = $this->toolsService->exportUserData($userId);

        return Response::json([
            'user_id' => $userId,
            'data' => $data,
        ]);
    }

    /**
     * Erase PII for a given user (GDPR right-to-erasure).
     *
     * Requires step-up authentication and a mandatory reason.
     */
    public function eraseUserData(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.tools.gdpr.erase');
        $this->requireStepUp($request);

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $userId = is_string($body['user_id'] ?? null) ? $body['user_id'] : '';
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if ($userId === '') {
            return Response::json(['error' => 'user_id is required'], 400);
        }

        if (strlen($reason) < 10) {
            return Response::json([
                'error' => 'A reason of at least 10 characters is required for data erasure',
            ], 400);
        }

        $result = $this->toolsService->eraseUserData($userId, $reason);

        return Response::json([
            'user_id' => $userId,
            'status' => 'erased',
            'comments_anonymized' => $result['comments_anonymized'],
            'content_anonymized' => $result['content_anonymized'],
            'form_submissions_deleted' => $result['form_submissions_deleted'],
            'newsletter_subscribers_deleted' => $result['newsletter_subscribers_deleted'],
        ]);
    }
}
