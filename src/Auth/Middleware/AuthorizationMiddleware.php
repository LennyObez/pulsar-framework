<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use JsonException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\SecurityContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Random\RandomException;
use SodiumException;

use function str_contains;

/**
 * Route-level middleware that triggers lazy identity resolution and checks authorization.
 *
 * Reads required permissions from the matched route's attributes ('permissions' key).
 * Returns 401 if unauthenticated, 403 if permission denied.
 */
final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private GateInterface $gate,
        private ?AuditLogger $auditLogger = null,
        private ?RequestContextHolder $contextHolder = null,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->getAttribute('_security_context');

        if ($securityContext === null) {
            return $this->unauthorizedResponse($request);
        }

        // Trigger lazy resolution
        $identity = $securityContext->identity();

        // Update request with resolved identity
        $request = $request->withAttribute('_identity', $identity);
        $request = $request->withAttribute('identity', $identity);

        // Enrich RequestContext with actor identity when authenticated
        if ($identity->isAuthenticated() && $this->contextHolder?->isAvailable() === true) {
            $this->contextHolder->set(
                $this->contextHolder->get()->withActor($identity->id()),
            );
        }

        if (!$identity->isAuthenticated()) {
            try {
                $this->auditAuthFailure($request);
            } catch (RandomException | JsonException | SodiumException) {
                // Audit logging failure must not disrupt authorization flow
            }
            return $this->unauthorizedResponse($request);
        }

        // Get required permissions from the matched route
        /** @var MatchedRoute|null $matchedRoute */
        $matchedRoute = $request->getAttribute('_route');

        if ($matchedRoute !== null) {
            $attributes = $matchedRoute->getAttributes();
            /** @var list<string> $permissions */
            $permissions = $attributes['permissions'] ?? [];

            // Default-deny: a route guarded by the `auth` middleware alias
            // but declaring no permissions is a configuration mistake, not
            // a grant. Falling through to allow here would let every
            // authenticated user past with no authorization check at all.
            // Operators who genuinely mean "any authenticated user" opt in
            // with the explicit `_authenticated` sentinel below.
            if ($permissions === []) {
                try {
                    $this->auditAuthzDenied($request, $identity->id(), 'no_permissions_declared');
                } catch (RandomException | JsonException | SodiumException) {
                    // Audit logging failure must not disrupt authorization flow
                }
                return $this->forbiddenResponse($request);
            }

            $path = $request->getUri()->getPath();

            foreach ($permissions as $permission) {
                // `_authenticated` is the explicit "any authenticated
                // user" marker — the caller opted in to the RBAC bypass.
                if ($permission === '_authenticated') {
                    continue;
                }

                $context = new PolicyContext(
                    permission: $permission,
                    resource: $path,
                );

                if ($this->gate->denies($identity, $permission, $context)) {
                    try {
                        $this->auditAuthzDenied($request, $identity->id(), $permission);
                    } catch (RandomException | JsonException | SodiumException) {
                        // Audit logging failure must not disrupt authorization flow
                    }
                    return $this->forbiddenResponse($request);
                }
            }
        } else {
            // Fail closed: without route context the required permissions are
            // unknown, so an authenticated request must not pass unchecked.
            try {
                $this->auditAuthzDenied($request, $identity->id(), 'no_route_context');
            } catch (RandomException | JsonException | SodiumException) {
                // Audit logging failure must not disrupt authorization flow
            }

            return $this->forbiddenResponse($request);
        }

        return $handler->handle($request);
    }

    private function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                ['error' => 'Unauthorized', 'status' => 401],
                ResponseStatus::Unauthorized->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Unauthorized->value,
            body: 'Unauthorized',
        );
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                ['error' => 'Forbidden', 'status' => 403],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: 'Forbidden',
        );
    }

    /**
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    private function auditAuthFailure(ServerRequestInterface $request): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Failure,
            actor: 'anonymous',
            action: 'authenticate',
            resource: $request->getUri()->getPath(),
            metadata: ['reason' => 'unauthenticated'],
        );
    }

    /**
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    private function auditAuthzDenied(ServerRequestInterface $request, string $actor, string $permission): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Denied,
            actor: $actor,
            action: 'authorize',
            resource: $request->getUri()->getPath(),
            metadata: ['permission' => $permission],
        );
    }
}
