<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Comments\CommentServiceInterface;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Http\Message\Response;

use function hash;
use function is_string;
use function trim;

/**
 * Public-facing comment submission controller.
 *
 * Handles comment form posts from content pages. Supports both
 * authenticated and guest submissions. CSRF validation is expected
 * to be handled by middleware before this controller is reached.
 *
 * @psalm-api Bound to a route by the CMS service provider; resolved
 *            from the DI container by the router.
 */
#[Internal(reason: 'CMS HTTP controller; implementation detail')]
final readonly class CommentController
{
    public function __construct(
        private CommentServiceInterface $commentService,
    ) {}

    public function submit(ServerRequestInterface $request, string $locale, string $contentId): Response
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var mixed $rawBody */
        $rawBody = $body['body'] ?? null;
        $commentBody = trim(is_string($rawBody) ? $rawBody : '');

        if ($commentBody === '') {
            return Response::validationError([
                ['field' => 'body', 'message' => 'Comment body is required', 'rule' => 'required'],
            ]);
        }

        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');
        $authorId = $identity?->isAuthenticated() === true ? $identity->id() : null;

        $guestName = null;
        $guestEmail = null;

        if ($authorId === null) {
            /** @var mixed $rawGuestName */
            $rawGuestName = $body['guest_name'] ?? null;
            $guestName = is_string($rawGuestName) ? trim($rawGuestName) : null;
            /** @var mixed $rawGuestEmail */
            $rawGuestEmail = $body['guest_email'] ?? null;
            $guestEmail = is_string($rawGuestEmail) ? trim($rawGuestEmail) : null;

            if ($guestName === null || $guestName === '') {
                return Response::validationError([
                    ['field' => 'guest_name', 'message' => 'Name is required for guest comments', 'rule' => 'required'],
                ]);
            }
        }

        /** @var mixed $remoteAddr */
        $remoteAddr = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $ipHash = hash('sha256', is_string($remoteAddr) ? $remoteAddr : '');
        $userAgentHash = hash('sha256', $request->getHeaderLine('User-Agent'));

        try {
            $comment = $this->commentService->submit(
                contentId: $contentId,
                body: $commentBody,
                authorId: $authorId,
                guestName: $guestName,
                guestEmail: $guestEmail,
                ipHash: $ipHash,
                userAgentHash: $userAgentHash,
            );

            return Response::json([
                'id' => $comment->id,
                'content_id' => $comment->contentId,
                'status' => $comment->status->value,
                'created_at' => $comment->createdAt->format('c'),
            ], 201);
        } catch (CmsException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }
}
