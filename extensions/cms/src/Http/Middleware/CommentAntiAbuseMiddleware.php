<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Middleware;

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
use function is_string;
use function time;

/**
 * Anti-abuse middleware for comment submissions.
 *
 * Delegates all spam checks to the shared AntiSpamPipeline,
 * which runs honeypot, duplicate detection, link density,
 * content quality, proof-of-work, and CAPTCHA checks.
 *
 * @psalm-api Registered with the router middleware pipeline by the
 *            CmsCoreServiceProvider; not new'd by name.
 */
#[Internal(reason: 'CMS middleware; not a public API surface')]
final readonly class CommentAntiAbuseMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AntiSpamPipelineInterface $pipeline,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rawParsedBody = $request->getParsedBody();

        if (!is_array($rawParsedBody)) {
            return $handler->handle($request);
        }

        /** @var array<string, mixed> $parsedBody */
        $parsedBody = $rawParsedBody;
        /** @var mixed $body */
        $body = $parsedBody['body'] ?? null;

        if (!is_string($body) || $body === '') {
            return $handler->handle($request);
        }

        $ipHash = $this->resolveIpHash($request);

        /** @var mixed $rawCaptchaToken */
        $rawCaptchaToken = $parsedBody['_captcha_token'] ?? null;

        $context = new AntiSpamContext(
            body: $body,
            ipHash: $ipHash,
            formFields: $parsedBody,
            captchaToken: is_string($rawCaptchaToken) ? $rawCaptchaToken : null,
            submissionTimestamp: time(),
            ip: $this->resolveIp($request),
        );

        $result = $this->pipeline->evaluate($context);

        if (!$result->passed) {
            $firstFailure = $result->failedChecks()[0] ?? 'unknown';

            // Honeypot failures return fake success to avoid tipping off bots
            if ($firstFailure === 'honeypot') {
                return Response::json(
                    ['status' => 'success', 'message' => 'Comment submitted successfully'],
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

    private function resolveIp(ServerRequestInterface $request): ?string
    {
        /** @var mixed $ip */
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    private function resolveIpHash(ServerRequestInterface $request): string
    {
        return hash('xxh3', $this->resolveIp($request) ?? 'unknown');
    }
}
