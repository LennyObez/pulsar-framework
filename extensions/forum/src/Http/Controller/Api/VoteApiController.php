<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Domain\VoteDirection;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Service\VoteServiceInterface;
use Pulsar\Http\Message\Response;

use function is_array;
use function is_string;

/**
 * Public REST API controller for forum votes.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class VoteApiController
{
    public function __construct(
        private VoteServiceInterface $voteService,
    ) {}

    /**
     * POST /api/v1/forum/threads/{id}/vote — Vote on a thread.
     */
    public function threadVote(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        $direction = $this->parseDirection($body);

        if ($direction === null) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['direction' => 'Must be "up" or "down"'],
            ], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $vote = $this->voteService->castThreadVote(
                $identity->id(),
                $id,
                $direction,
                $tenantId,
            );

            return Response::json([
                'data' => [
                    'id' => $vote->id,
                    'thread_id' => $vote->threadId,
                    'direction' => $vote->value->value,
                ],
            ], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/posts/{id}/vote — Vote on a post.
     */
    public function postVote(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        $direction = $this->parseDirection($body);

        if ($direction === null) {
            return Response::json([
                'error' => 'Validation failed',
                'status' => 422,
                'details' => ['direction' => 'Must be "up" or "down"'],
            ], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        try {
            $vote = $this->voteService->castPostVote(
                $identity->id(),
                $id,
                $direction,
                $tenantId,
            );

            return Response::json([
                'data' => [
                    'id' => $vote->id,
                    'post_id' => $vote->postId,
                    'direction' => $vote->value->value,
                ],
            ], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /api/v1/forum/threads/{id}/vote — Remove a thread vote.
     */
    public function removeThreadVote(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        try {
            $this->voteService->removeThreadVote($identity->id(), $id);

            return Response::json(['data' => ['thread_id' => $id, 'status' => 'removed']]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * DELETE /api/v1/forum/posts/{id}/vote — Remove a post vote.
     */
    public function removePostVote(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        try {
            $this->voteService->removePostVote($identity->id(), $id);

            return Response::json(['data' => ['post_id' => $id, 'status' => 'removed']]);
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

    /**
     * @param array<string, mixed> $body
     */
    private function parseDirection(array $body): ?VoteDirection
    {
        $raw = is_string($body['direction'] ?? null) ? $body['direction'] : null;

        return match ($raw) {
            'up' => VoteDirection::Up,
            'down' => VoteDirection::Down,
            default => null,
        };
    }
}
