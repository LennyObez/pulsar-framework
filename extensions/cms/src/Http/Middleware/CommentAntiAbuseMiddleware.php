<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Comments\AntiAbuseHeuristics;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function hash;
use function is_array;
use function is_string;

/**
 * Anti-abuse middleware for comment submissions.
 *
 * Runs multiple heuristic checks against the comment body:
 * - Duplicate detection (same body from same IP within time window)
 * - Link density (rejects link spam)
 * - Content length (rejects excessively long comments)
 * - Character repetition (rejects keyboard-mashing spam)
 *
 * All rejections return 422 Unprocessable Entity with a descriptive error.
 */
#[Internal(reason: 'CMS middleware — not a public API surface')]
final readonly class CommentAntiAbuseMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AntiAbuseHeuristics $heuristics,
        private int $maxLinks = 3,
        private int $maxLength = 10000,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return $handler->handle($request);
        }

        $body = $parsedBody['body'] ?? null;

        if (!is_string($body) || $body === '') {
            return $handler->handle($request);
        }

        // Check content length first (cheapest check)
        if ($this->heuristics->isTooLong($body, $this->maxLength)) {
            return $this->reject("Comment body exceeds maximum length of $this->maxLength characters");
        }

        // Check excessive character repetition
        if ($this->heuristics->hasExcessiveRepetition($body)) {
            return $this->reject('Comment body contains excessive character repetition');
        }

        // Check link spam
        if ($this->heuristics->isLinkSpam($body, $this->maxLinks)) {
            return $this->reject("Comment body contains more than $this->maxLinks links");
        }

        // Check duplicate submission
        $bodyHash = $this->heuristics->hashBody($body);
        $ipHash = $this->resolveIpHash($request);

        if ($this->heuristics->isDuplicate($bodyHash, $ipHash)) {
            return $this->reject('Duplicate comment detected — this comment was already submitted recently');
        }

        // Record this submission for future duplicate detection
        $this->heuristics->recordSubmission($bodyHash, $ipHash);

        return $handler->handle($request);
    }

    private function reject(string $error): ResponseInterface
    {
        return Response::json(
            ['error' => 'Unprocessable Entity', 'message' => $error],
            ResponseStatus::UnprocessableEntity->value,
        );
    }

    private function resolveIpHash(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $raw = is_string($ip) ? $ip : 'unknown';

        return hash('xxh3', $raw);
    }
}
