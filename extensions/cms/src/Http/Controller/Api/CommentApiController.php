<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Comments\Comment;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Comments\ModerationStatus;
use Pulsar\Http\Message\Response;

use function array_map;
use function hash;
use function max;
use function min;

/**
 * Public API for fetching approved comments on content pages.
 *
 * Used by the <cms-comments> custom element to render comment threads.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class CommentApiController
{
    public function __construct(
        private CommentRepositoryInterface $commentRepository,
    ) {}

    /**
     * GET /api/cms/comments?content_id={id}&page=1&per_page=20&sort=newest
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var string|null $contentId */
        $contentId = $params['content_id'] ?? null;

        if ($contentId === null || $contentId === '') {
            return Response::json(['error' => 'content_id is required'], 400);
        }

        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);

        /** @var int|string $rawPerPage */
        $rawPerPage = $params['per_page'] ?? 20;
        $perPage = min(100, max(1, (int) $rawPerPage));

        $result = $this->commentRepository->findByContent(
            contentId: $contentId,
            status: ModerationStatus::Approved,
            page: $page,
            perPage: $perPage,
        );

        return Response::json([
            'data' => array_map(self::serialize(...), $result->items),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $result->hasMore,
            ],
        ]);
    }

    /**
     * Serialize a comment for the public API.
     *
     * Redacts PII fields (email hashed for Gravatar, IP/UA hashes omitted).
     *
     * @return array<string, mixed>
     */
    private static function serialize(Comment $comment): array
    {
        $gravatarHash = $comment->guestEmail !== null
            ? hash('md5', strtolower(trim($comment->guestEmail)))
            : null;

        return [
            'id' => $comment->id,
            'content_id' => $comment->contentId,
            'parent_id' => $comment->parentId,
            'author_id' => $comment->authorId,
            'author_name' => $comment->guestName,
            'gravatar_hash' => $gravatarHash,
            'body' => $comment->body,
            'edited_at' => $comment->editedAt?->format('c'),
            'created_at' => $comment->createdAt->format('c'),
        ];
    }
}
