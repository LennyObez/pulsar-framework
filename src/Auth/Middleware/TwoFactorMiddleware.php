<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Route-level middleware that blocks access when 2FA verification is pending.
 *
 * Triggers lazy identity resolution via SecurityContext, then checks
 * if the identity's two-factor status is Pending. If so, returns 403.
 */
final readonly class TwoFactorMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(Request $request, callable $next): Response
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->attribute('_security_context');

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

        return $next($request);
    }

    private function forbiddenResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(
                ['error' => 'Two-factor authentication verification required', 'status' => 403],
                ResponseStatus::Forbidden,
            );
        }

        return new Response(
            body: 'Two-factor authentication verification required',
            status: ResponseStatus::Forbidden,
        );
    }
}
