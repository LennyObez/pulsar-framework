<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Tools\ToolsServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function is_string;
use function strlen;

/**
 * Admin controller for CMS administrative tools.
 *
 * Provides GDPR data export and PII erasure endpoints.
 * Both operations require step-up authentication and the cms.tools.export permission.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ToolsController
{
    public function __construct(
        private ToolsServiceInterface $toolsService,
        private GateInterface $gate,
    ) {}

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
        $this->authorize($identity, 'cms.tools.export');
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
        ]);
    }

    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw new RuntimeException('Step-up authentication required for this action');
        }
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
}
