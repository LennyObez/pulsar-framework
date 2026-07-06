<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Internal;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

/**
 * Advertises the Private Access Token challenge on denied responses.
 *
 * When a downstream layer rejects a request with 401 or 403 — including the
 * adaptive risk engine's block — this middleware adds a
 * `WWW-Authenticate: PrivateToken` challenge (RFC 9577) so a Privacy Pass-capable
 * client can redeem a token and retry; the retry then bypasses the challenge via
 * {@see PrivateTokenBypassProvider}. It only augments responses that already
 * deny access, so it never adds friction to successful requests, and must sit
 * outside the middleware it augments (it is piped last).
 */
#[Internal]
final readonly class PrivateTokenChallengeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PrivacyPassChallengeIssuer $issuer,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $status = $response->getStatusCode();
        if ($status === ResponseStatus::Unauthorized->value || $status === ResponseStatus::Forbidden->value) {
            return $response->withAddedHeader('WWW-Authenticate', $this->issuer->headerValue());
        }

        return $response;
    }
}
