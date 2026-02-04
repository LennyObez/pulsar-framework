<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;

/**
 * Global middleware that attaches a SecurityContext and AnonymousIdentity to every request.
 *
 * This middleware is intentionally cheap: it does NOT trigger full authentication.
 * It only creates the lazy SecurityContext wrapper and sets the default identity
 * to AnonymousIdentity. Full resolution happens when downstream middleware
 * (AuthorizationMiddleware, TwoFactorMiddleware) or the handler accesses
 * the SecurityContext.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthManagerInterface $authManager,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        $securityContext = new SecurityContext($this->authManager, $request);

        $request = $request->withAttribute('_security_context', $securityContext);
        $request = $request->withAttribute('_identity', new AnonymousIdentity());

        return $next($request);
    }
}
