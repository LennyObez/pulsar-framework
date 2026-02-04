<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;

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
    ) {}

    public function process(Request $request, callable $next): Response
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->attribute('_security_context');

        if ($securityContext === null) {
            return $this->unauthorizedResponse($request);
        }

        // Trigger lazy resolution
        $identity = $securityContext->identity();

        // Update request with resolved identity
        $request = $request->withAttribute('_identity', $identity);

        if (!$identity->isAuthenticated()) {
            $this->auditAuthFailure($request, 'unauthenticated');
            return $this->unauthorizedResponse($request);
        }

        // Get required permissions from the matched route
        /** @var MatchedRoute|null $matchedRoute */
        $matchedRoute = $request->attribute('_route');

        if ($matchedRoute !== null) {
            $attributes = $matchedRoute->getAttributes();
            /** @var list<string> $permissions */
            $permissions = $attributes['permissions'] ?? [];

            foreach ($permissions as $permission) {
                $context = new PolicyContext(
                    permission: $permission,
                    resource: $request->path,
                );

                if ($this->gate->denies($identity, $permission, $context)) {
                    $this->auditAuthzDenied($request, $identity->id(), $permission);
                    return $this->forbiddenResponse($request);
                }
            }
        }

        return $next($request);
    }

    private function unauthorizedResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(
                ['error' => 'Unauthorized', 'status' => 401],
                ResponseStatus::Unauthorized,
            );
        }

        return new Response(
            body: 'Unauthorized',
            status: ResponseStatus::Unauthorized,
        );
    }

    private function forbiddenResponse(Request $request): Response
    {
        if ($request->wantsJson()) {
            return Response::json(
                ['error' => 'Forbidden', 'status' => 403],
                ResponseStatus::Forbidden,
            );
        }

        return new Response(
            body: 'Forbidden',
            status: ResponseStatus::Forbidden,
        );
    }

    private function auditAuthFailure(Request $request, string $reason): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Failure,
            actor: 'anonymous',
            action: 'authenticate',
            resource: $request->path,
            metadata: ['reason' => $reason],
        );
    }

    private function auditAuthzDenied(Request $request, string $actor, string $permission): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::Authorization,
            outcome: AuditOutcome::Denied,
            actor: $actor,
            action: 'authorize',
            resource: $request->path,
            metadata: ['permission' => $permission],
        );
    }
}
