<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;

use function str_contains;

/**
 * Route-level middleware that blocks access when 2FA verification is pending.
 *
 * Triggers lazy identity resolution via SecurityContext, then checks
 * if the identity's two-factor status is Pending. If so, returns 403.
 */
final readonly class TwoFactorMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->getAttribute('_security_context');

        if ($securityContext === null) {
            return $this->forbiddenResponse($request);
        }

        // Trigger lazy resolution
        $identity = $securityContext->identity();

        // Update request with resolved identity
        $request = $request->withAttribute('_identity', $identity);

        if ($identity->isAuthenticated() && $identity->twoFactorStatus() === TwoFactorStatus::Pending) {
            return $this->forbiddenResponse($request);
        }

        return $handler->handle($request);
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                ['error' => 'Two-factor authentication verification required', 'status' => 403],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: 'Two-factor authentication verification required',
        );
    }
}
