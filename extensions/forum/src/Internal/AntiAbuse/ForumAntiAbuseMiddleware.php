<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\AntiAbuse;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Security\AntiSpam\AntiSpamContext;
use Pulsar\Security\AntiSpam\AntiSpamPipelineInterface;

use function hash;
use function is_array;
use function is_int;
use function is_string;
use function time;

/**
 * Anti-abuse middleware for forum post submissions.
 *
 * Delegates all spam checks to the shared AntiSpamPipeline,
 * which runs honeypot, duplicate detection, link density,
 * content quality, proof-of-work, account age, and reputation
 * cooldown checks.
 */
#[Internal(reason: 'Anti-abuse internals; not part of public API')]
final readonly class ForumAntiAbuseMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AntiSpamPipelineInterface $pipeline,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parsedBody = $request->getParsedBody();

        if (!is_array($parsedBody)) {
            return $handler->handle($request);
        }

        /** @var array<string, mixed> $parsedBody */
        $body = $parsedBody['body'] ?? null;

        if (!is_string($body) || $body === '') {
            return $handler->handle($request);
        }

        $ipHash = $this->resolveIpHash($request);

        // Extract user context from request attributes (set by auth middleware)
        $userId = $request->getAttribute('user_id');
        $accountAgeSeconds = $request->getAttribute('account_age_seconds');
        $reputationTier = $request->getAttribute('reputation_tier');

        /** @var list<string> $recentBodies */
        $recentBodies = $request->getAttribute('recent_post_bodies') ?? [];

        $context = new AntiSpamContext(
            body: $body,
            ipHash: $ipHash,
            userId: is_string($userId) ? $userId : null,
            accountAgeSeconds: is_int($accountAgeSeconds) ? $accountAgeSeconds : null,
            reputationTier: is_string($reputationTier) ? $reputationTier : 'new',
            recentBodies: $recentBodies,
            formFields: $parsedBody,
            powChallenge: is_string($parsedBody['_pow_challenge'] ?? null) ? $parsedBody['_pow_challenge'] : null,
            powNonce: is_string($parsedBody['_pow_nonce'] ?? null) ? $parsedBody['_pow_nonce'] : null,
            captchaToken: is_string($parsedBody['_captcha_token'] ?? null) ? $parsedBody['_captcha_token'] : null,
            submissionTimestamp: time(),
        );

        $result = $this->pipeline->evaluate($context);

        if (!$result->passed) {
            $firstFailure = $result->failedChecks()[0] ?? 'unknown';

            // Honeypot failures return fake success
            if ($firstFailure === 'honeypot') {
                return Response::json(
                    ['status' => 'success', 'message' => 'Post submitted successfully'],
                    ResponseStatus::OK->value,
                );
            }

            $reason = null;

            foreach ($result->checkResults as $checkResult) {
                if (!$checkResult->passed) {
                    $reason = $checkResult->reason;

                    break;
                }
            }

            return Response::json(
                ['error' => 'Unprocessable Entity', 'message' => $reason ?? 'Submission rejected by anti-spam checks'],
                ResponseStatus::UnprocessableEntity->value,
            );
        }

        return $handler->handle($request);
    }

    private function resolveIpHash(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $raw = is_string($ip) ? $ip : 'unknown';

        return hash('xxh3', $raw);
    }
}
