<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Forum\Config\ForumConfig;
use Pulsar\Extension\Forum\Content\ForumBodyPolicy;
use Pulsar\Extension\Forum\Content\MarkdownRendererInterface;
use Pulsar\Extension\Forum\Domain\ThreadType;
use Pulsar\Extension\Forum\Exception\ForumException;
use Pulsar\Extension\Forum\Service\ForumServiceInterface;
use Pulsar\Extension\Forum\Subscription\ThreadSubscription;
use Pulsar\Extension\Forum\Subscription\ThreadSubscriptionRepositoryInterface;
use Pulsar\Extension\Forum\Support\UuidGenerator;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function hash;
use function is_array;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for forum threads.
 */
#[Internal(reason: 'Forum REST API controller; implementation detail')]
final readonly class ThreadApiController
{
    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private ThreadSubscriptionRepositoryInterface $subscriptionRepository,
        private ForumServiceInterface $forumService,
        private MarkdownRendererInterface $markdown,
        private ForumBodyPolicy $bodyPolicy,
        private ForumConfig $config,
        private ?GateInterface $gate = null,
    ) {}

    /**
     * GET /api/v1/forum/threads: List recent threads.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, is_numeric($params['page'] ?? null) ? (int) $params['page'] : 1);
        $perPage = min(100, max(1, is_numeric($params['per_page'] ?? null) ? (int) $params['per_page'] : $this->config->threadsPerPage));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $result = $this->threadRepository->findRecent($page, $perPage, $tenantId);
        $data = array_map(self::serializeThread(...), $result->items);

        return Response::json(['data' => $data, 'pagination' => $result->metaToArray()])
            ->withHeader('X-Total-Count', (string) $result->total)
            ->withHeader('X-Page', (string) $page)
            ->withHeader('X-Per-Page', (string) $perPage);
    }

    /**
     * POST /api/v1/forum/threads: Create a new thread.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        $errors = $this->validateCreatePayload($body);

        if ($errors !== []) {
            return Response::json(['error' => 'Validation failed', 'status' => 422, 'details' => $errors], 422);
        }

        $type = ThreadType::tryFrom(is_string($body['type'] ?? null) ? $body['type'] : 'discussion');

        if ($type === null) {
            return Response::json(['error' => 'Validation failed', 'status' => 422, 'details' => ['type' => 'Invalid thread type']], 422);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $serverParams = $request->getServerParams();
        $ipHash = hash('xxh3', is_string($serverParams['REMOTE_ADDR'] ?? null) ? $serverParams['REMOTE_ADDR'] : 'unknown');
        $userAgentHash = hash('xxh3', $request->getHeaderLine('User-Agent'));

        $rawBody = is_string($body['body'] ?? null) ? $body['body'] : '';

        try {
            $thread = $this->forumService->createThread(
                categoryId: is_string($body['category_id'] ?? null) ? $body['category_id'] : '',
                authorId: $identity->id(),
                title: is_string($body['title'] ?? null) ? $body['title'] : '',
                slug: is_string($body['slug'] ?? null) ? $body['slug'] : '',
                type: $type,
                body: $rawBody,
                bodyHtml: $this->bodyPolicy->sanitize($this->markdown->render($rawBody)),
                ipHash: $ipHash,
                userAgentHash: $userAgentHash,
                tenantId: $tenantId,
            );

            return Response::json(['data' => self::serializeThread($thread)], 201);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/v1/forum/threads/{id}: Show a single thread.
     */
    public function show(ServerRequestInterface $request, string $id): Response
    {
        $thread = $this->threadRepository->findById($id);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found', 'status' => 404], 404);
        }

        return Response::json(['data' => self::serializeThread($thread)]);
    }

    /**
     * PUT /api/v1/forum/threads/{id}: Update a thread.
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $thread = $this->threadRepository->findById($id);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found', 'status' => 404], 404);
        }

        if ($thread->authorId !== $identity->id()) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        $parsed = $request->getParsedBody();

        if (!is_array($parsed)) {
            return Response::json(['error' => 'Invalid request body', 'status' => 400], 400);
        }

        /** @var array<string, mixed> $body */
        $body = $parsed;

        $title = is_string($body['title'] ?? null) ? $body['title'] : $thread->title;
        $slug = is_string($body['slug'] ?? null) ? $body['slug'] : $thread->slug;

        $updated = $thread->editTitle($title, $slug);
        $this->threadRepository->save($updated);

        return Response::json(['data' => self::serializeThread($updated)]);
    }

    /**
     * DELETE /api/v1/forum/threads/{id}: Soft delete a thread.
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $thread = $this->threadRepository->findById($id);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found', 'status' => 404], 404);
        }

        $isOwner = $thread->authorId === $identity->id();
        $isModerator = $this->gate !== null && $this->gate->allows($identity, 'forum.moderate');

        if (!$isOwner && !$isModerator) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        try {
            // Forward the moderator flag computed above so the service
            // can run its own author-check (defense in depth, MED-4).
            $this->forumService->deleteThread($id, $identity->id(), $isModerator);

            return Response::json(['data' => ['id' => $id, 'status' => 'deleted']]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/threads/{id}/lock: Lock a thread.
     */
    public function lock(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        if ($this->gate !== null && $this->gate->denies($identity, 'forum.moderate')) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        try {
            $thread = $this->forumService->lockThread($id, $identity->id());

            return Response::json(['data' => self::serializeThread($thread)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/threads/{id}/pin: Pin a thread.
     */
    public function pin(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        if ($this->gate !== null && $this->gate->denies($identity, 'forum.moderate')) {
            return Response::json(['error' => 'Forbidden', 'status' => 403], 403);
        }

        try {
            $thread = $this->forumService->pinThread($id, $identity->id());

            return Response::json(['data' => self::serializeThread($thread)]);
        } catch (ForumException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/forum/threads/{id}/subscribe: Subscribe to a thread.
     */
    public function subscribe(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $thread = $this->threadRepository->findById($id);

        if ($thread === null) {
            return Response::json(['error' => 'Thread not found', 'status' => 404], 404);
        }

        $existing = $this->subscriptionRepository->findByUserAndThread($identity->id(), $id);

        if ($existing !== null) {
            return Response::json(['data' => ['subscribed' => true, 'thread_id' => $id]]);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $subscription = ThreadSubscription::subscribe(
            id: UuidGenerator::v7(),
            userId: $identity->id(),
            threadId: $id,
            tenantId: $tenantId,
        );

        $this->subscriptionRepository->save($subscription);

        return Response::json(['data' => ['subscribed' => true, 'thread_id' => $id]], 201);
    }

    /**
     * DELETE /api/v1/forum/threads/{id}/subscribe: Unsubscribe from a thread.
     */
    public function unsubscribe(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);

        $subscription = $this->subscriptionRepository->findByUserAndThread($identity->id(), $id);

        if ($subscription === null) {
            return Response::json(['data' => ['subscribed' => false, 'thread_id' => $id]]);
        }

        $this->subscriptionRepository->delete($subscription);

        return Response::json(['data' => ['subscribed' => false, 'thread_id' => $id]]);
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
     * @return array<string, string>
     */
    private function validateCreatePayload(array $body): array
    {
        $errors = [];

        if (!is_string($body['title'] ?? null) || ($body['title'] ?? '') === '') {
            $errors['title'] = 'Title is required';
        }

        if (!is_string($body['slug'] ?? null) || ($body['slug'] ?? '') === '') {
            $errors['slug'] = 'Slug is required';
        }

        if (!is_string($body['category_id'] ?? null) || ($body['category_id'] ?? '') === '') {
            $errors['category_id'] = 'Category ID is required';
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serializeThread(Thread $thread): array
    {
        return [
            'id' => $thread->id,
            'tenant_id' => $thread->tenantId,
            'category_id' => $thread->categoryId,
            'author_id' => $thread->authorId,
            'title' => $thread->title,
            'slug' => $thread->slug,
            'type' => $thread->type->value,
            'status' => $thread->status->value,
            'is_pinned' => $thread->isPinned,
            'is_locked' => $thread->isLocked,
            'solved_post_id' => $thread->solvedPostId,
            'reply_count' => $thread->replyCount,
            'view_count' => $thread->viewCount,
            'vote_score' => $thread->voteScore,
            'last_activity_at' => $thread->lastActivityAt?->format('c'),
            'created_at' => $thread->createdAt->format('c'),
            'updated_at' => $thread->updatedAt->format('c'),
        ];
    }
}
