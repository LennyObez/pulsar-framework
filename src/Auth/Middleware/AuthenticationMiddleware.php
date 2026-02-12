<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;

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

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $securityContext = new SecurityContext($this->authManager, $request);

        $request = $request->withAttribute('_security_context', $securityContext);
        $request = $request->withAttribute('_identity', new AnonymousIdentity());

        return $handler->handle($request);
    }
}
