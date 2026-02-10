<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Service\ModerationServiceInterface;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;

/**
 * Public REST API controller for content reporting.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class ReportApiController
{
    public function __construct(
        private ModerationServiceInterface $moderationService,
    ) {}

    /**
     * POST /api/v1/forum/threads/{id}/report — Report a thread.
     */
    public function reportThread(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if ($reason === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['reason' => 'Reason is required'],
            ], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $report = $this->moderationService->submitThreadReport(
                $id,
                $identity->id(),
                $reason,
                $tenantId,
            );

            return Response::json([
                'data' => [
                    'id' => $report->id,
                    'thread_id' => $report->threadId,
                    'status' => $report->status->value,
                ],
            ], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/posts/{id}/report — Report a post.
     */
    public function reportPost(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';

        if ($reason === '') {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['reason' => 'Reason is required'],
            ], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $report = $this->moderationService->submitPostReport(
                $id,
                $identity->id(),
                $reason,
                $tenantId,
            );

            return Response::json([
                'data' => [
                    'id' => $report->id,
                    'post_id' => $report->postId,
                    'status' => $report->status->value,
                ],
            ], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw ForumException::unauthorized('authentication_required');
        }

        return $identity;
    }
}
