<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthenticationState;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;

/**
 * Global middleware that establishes the request's security context.
 *
 * This middleware is intentionally cheap: it does NOT trigger full
 * authentication. It builds the lazy {@see SecurityContext} wrapper and
 * publishes it into {@see AuthenticationState}. Full resolution happens when a
 * downstream frame — {@see AuthorizationMiddleware}, {@see TwoFactorMiddleware},
 * the model binding layer, or the handler — asks for the identity.
 *
 * ## The identity attributes are an output, never an input
 *
 * `_identity` and `identity` are still written, because extension controllers
 * read them. They are no longer READ here, and that is the change: this
 * middleware used to preserve an already-authenticated `identity` attribute in
 * place of the context it would otherwise build, which made a PSR-7 attribute
 * an input to authentication. Every frame piped ahead of this one — and every
 * wiring that pipes a global middleware before AuthWiring does — could
 * therefore name the caller and be believed, all the way through to the
 * authorization decision on a bound route.
 *
 * The one caller that legitimately knows the identity before the guards do is
 * `pulsar serve --dev-identity`. It now says so where the framework can see it:
 * {@see \Pulsar\Dev\DevServerBootstrap} publishes a
 * {@see SecurityContext::established()} context into {@see AuthenticationState}
 * before the kernel runs, and the check below leaves an already-established
 * context alone. A pre-established context is a decision made by the
 * composition root; an attribute is a decision made by whatever wrote last.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthManagerInterface $authManager,
        private AuthenticationState $state,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $securityContext = $this->state->context();

        if ($securityContext === null) {
            $securityContext = new SecurityContext($this->authManager, $request);
            $this->state->establish($securityContext);
        }

        $request = $request->withAttribute('_security_context', $securityContext);

        // tryIdentity(), not identity(): reporting the caller must not decide to
        // authenticate one. It answers non-null only when something already
        // resolved the context — the dev server's pre-established identity —
        // so a public route still reaches its handler with no guard consulted.
        $identity = $securityContext->tryIdentity() ?? new AnonymousIdentity();

        $request = $request->withAttribute('_identity', $identity);
        $request = $request->withAttribute('identity', $identity);

        return $handler->handle($request);
    }
}
