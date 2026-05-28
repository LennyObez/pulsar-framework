<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Global middleware that attaches a SecurityContext and identity to every request.
 *
 * This middleware is intentionally cheap: it does NOT trigger full authentication.
 * It only creates the lazy SecurityContext wrapper and sets the default identity
 * to AnonymousIdentity. Full resolution happens when downstream middleware
 * (AuthorizationMiddleware, TwoFactorMiddleware) or the handler accesses
 * the SecurityContext.
 *
 * If the request already carries an authenticated identity (e.g. injected by a dev
 * server router), this middleware preserves it instead of overwriting with anonymous.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthManagerInterface $authManager,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $securityContext = new SecurityContext($this->authManager, $request);
        $request = $request->withAttribute('_security_context', $securityContext);

        // Preserve an already-authenticated identity (e.g. dev router injection)
        /** @var mixed $existing */
        $existing = $request->getAttribute('identity');

        if ($existing instanceof IdentityInterface && $existing->isAuthenticated()) {
            $request = $request->withAttribute('_identity', $existing);
        } else {
            $anonymousIdentity = new AnonymousIdentity();
            $request = $request->withAttribute('_identity', $anonymousIdentity);
            $request = $request->withAttribute('identity', $anonymousIdentity);
        }

        return $handler->handle($request);
    }
}
