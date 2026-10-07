<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditActor;
use Pulsar\Auth\Guard\SessionGuard;
use Pulsar\Auth\Security\AccountTakeoverGuard;
use Pulsar\Auth\Security\SensitiveOperation;
use Pulsar\Auth\Security\TakeoverRiskLevel;
use Pulsar\Auth\SecurityContext;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\DispatchedRouteAwareInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Session\SessionManager;
use Throwable;

use function is_string;
use function max;
use function str_contains;

/**
 * Route-level middleware that runs {@see AccountTakeoverGuard} over a
 * sensitive operation before the handler can perform it.
 *
 * A route names the operation it performs in its attributes, the same way it
 * names the permissions it requires:
 *
 * ```php
 * new Route(
 *     methods: [Method::POST],
 *     path: '/account/password',
 *     handler: [PasswordController::class, 'change'],
 *     attributes: ['sensitive_operation' => 'password_change'],
 *     middleware: ['auth', 'sensitive'],
 * );
 * ```
 *
 * Two checks run, in this order, and each has a different failure mode:
 *
 * 1. **Re-authentication window.** Changing a password with a session opened
 *    hours ago is the classic takeover: the attacker holds a stolen cookie, not
 *    the credentials. The guard refuses the operation unless the person proved
 *    who they are inside the window. Either a fresh login
 *    ({@see SessionGuard::lastAuthenticatedAt()}) or a completed step-up
 *    challenge ({@see StepUpMiddleware::stepUpAuthenticatedAt()}) satisfies it,
 *    so an application already running the `step-up` alias does not prompt
 *    twice for one request.
 * 2. **Takeover risk.** A credential change arriving from a different IP *and*
 *    a different device than the session was opened from is refused outright;
 *    one of the two is allowed through but recorded, because a mobile network
 *    re-issuing an address is ordinary and a swapped device is not.
 *
 * Fail-closed throughout. A route carrying this middleware without a
 * recognised `sensitive_operation`, a request with no security context, an
 * unauthenticated identity, or a session with no metadata to compare against
 * all produce 403 rather than a silent pass — the same default-deny stance
 * {@see AuthorizationMiddleware} takes for a permission-less route. A control
 * that waves a request through when it cannot evaluate it is the failure this
 * middleware exists to remove.
 */
final readonly class SensitiveOperationMiddleware implements DispatchedRouteAwareInterface, MiddlewareInterface
{
    /** Route attribute naming the {@see SensitiveOperation} a route performs. */
    public const string ROUTE_ATTRIBUTE = 'sensitive_operation';

    public function __construct(
        private AccountTakeoverGuard $guard,
        private SessionGuard $sessionGuard,
        private SessionManager $session,
        private ?AuditLogger $auditLogger = null,
        private ?LoggerInterface $logger = null,
        private ?MatchedRoute $dispatchedRoute = null,
    ) {}

    /**
     * Bind a copy of this middleware to the route the kernel is dispatching.
     *
     * A copy, not a mutation: one instance serves every request for the process
     * lifetime and a persistent worker interleaves Fiber-suspended requests
     * through it, so a route stored on the shared object would be another
     * request's route. The class is readonly, so the copy is a construction
     * rather than a `clone`.
     */
    #[Override]
    public function forDispatchedRoute(MatchedRoute $route): self
    {
        return new self(
            $this->guard,
            $this->sessionGuard,
            $this->session,
            $this->auditLogger,
            $this->logger,
            $route,
        );
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $operation = $this->operationFor();

        if ($operation === null) {
            return $this->refuse($request, 'unknown', 'operation_not_declared', null);
        }

        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->getAttribute('_security_context');

        if ($securityContext === null) {
            return $this->refuse($request, $operation->value, 'no_security_context', null);
        }

        $identity = $securityContext->identity();

        if (!$identity->isAuthenticated()) {
            return $this->refuse($request, $operation->value, 'unauthenticated', null);
        }

        $lastAuthenticatedAt = $this->lastAuthenticatedAt($identity->id());

        if ($this->guard->requiresReauthentication($operation, $lastAuthenticatedAt)) {
            return $this->refuse($request, $operation->value, 'reauthentication_required', $identity->id());
        }

        $metadata = $this->session->metadata;

        if ($metadata === null) {
            return $this->refuse($request, $operation->value, 'no_session_metadata', $identity->id());
        }

        // The guard records its verdict against SessionMetadata::$userId, and
        // nothing in the framework populates that field — SessionManager's
        // setUserId() has no caller in src/, so it is null on every real
        // session. A detected takeover therefore reached the audit logger with
        // a null actor, which throws rather than writing, and the one record
        // this control exists to produce was the thing that got lost. The
        // identity authenticated above is the authoritative answer to "whose
        // account", so it is stamped onto the copy handed to the guard; the
        // session's own metadata is left alone.
        $metadata = $metadata->withUserId($identity->id());

        // evaluate() writes its own audit entry for both elevated and high
        // risk, so nothing is logged here for the refusal below: doing so
        // would put the same decision in the chain twice.
        try {
            $risk = $this->guard->evaluate($operation, $request, $metadata);
        } catch (Throwable $e) {
            // The sink is the only collaborator here that fails for reasons
            // having nothing to do with the request — a full disk, an
            // unreachable database. Letting that decide the request would mean
            // an unevaluated sensitive operation proceeds, or that the caller
            // sees a 500 where the guard had already found the risk ordinary.
            // Refuse instead: a control an attacker can switch off by filling
            // a disk is not a control. The guard logs the risk itself before
            // it records it, so the operator's log still carries the finding.
            $this->logger?->critical(
                'Account takeover evaluation could not be recorded; refusing the sensitive operation',
                [
                    'operation' => $operation->value,
                    'user_id' => $identity->id(),
                    'error' => $e->getMessage(),
                ],
            );

            return $this->forbiddenResponse($request);
        }

        if ($risk->level === TakeoverRiskLevel::High) {
            return $this->forbiddenResponse($request);
        }

        return $handler->handle($request);
    }

    /**
     * The operation the DISPATCHED route names, or none.
     *
     * Read from the route the kernel handed down, not from the `_route` request
     * attribute. This middleware is the route-level `sensitive` alias, so other
     * route middleware runs in front of it and every one of them can hand the
     * next frame a request carrying whatever route it likes while the kernel
     * goes on dispatching the one it matched. A substituted route that simply
     * omits the `sensitive_operation` attribute made this method return null and
     * the whole control was skipped — the guard never ran, and the handler
     * performed the operation with no re-authentication demanded. See
     * {@see \Pulsar\Http\Middleware\DispatchedRouteAwareInterface}.
     *
     * A copy that was never bound has no route and names no operation, which is
     * the same answer it gave for a request with no route context before.
     */
    private function operationFor(): ?SensitiveOperation
    {
        $matchedRoute = $this->dispatchedRoute;

        if ($matchedRoute === null) {
            return null;
        }

        /** @var mixed $declared */
        $declared = $matchedRoute->getAttributes()[self::ROUTE_ATTRIBUTE] ?? null;

        if ($declared instanceof SensitiveOperation) {
            return $declared;
        }

        return is_string($declared) ? SensitiveOperation::tryFrom($declared) : null;
    }

    /**
     * The most recent proof of identity this session holds.
     *
     * A step-up challenge completed after login is more recent evidence than
     * the login itself, so the later of the two wins; a session with neither
     * returns null and every configured operation demands re-authentication.
     */
    private function lastAuthenticatedAt(string $identityId): ?int
    {
        $loginAt = $this->sessionGuard->lastAuthenticatedAt();
        $stepUpAt = StepUpMiddleware::stepUpAuthenticatedAt($this->session, $identityId);

        if ($loginAt === null) {
            return $stepUpAt;
        }

        return $stepUpAt === null ? $loginAt : max($loginAt, $stepUpAt);
    }

    private function refuse(
        ServerRequestInterface $request,
        string $operation,
        string $reason,
        ?string $actor,
    ): ResponseInterface {
        try {
            // A refusal reached before the identity resolves still belongs in
            // the chain. Naming the actor anonymous keeps the entry; a null
            // actor with no request context would make the logger throw and
            // the record would be the one thing lost.
            $this->auditLogger?->log(
                event: AuditEvent::SecurityEvent,
                outcome: AuditOutcome::Denied,
                actor: $actor ?? AuditActor::anonymous(),
                action: 'auth.sensitive_operation.refused',
                resource: $request->getUri()->getPath(),
                metadata: [
                    'operation' => $operation,
                    'reason' => $reason,
                ],
            );
        } catch (Throwable $e) {
            // An audit-sink fault must not convert a refusal into a pass: the
            // response below is returned either way. Catching Throwable rather
            // than the three exception types AuditLogger declares is
            // deliberate — a sink signals a failed write with SecurityException,
            // which is not among them, so the narrow clause let precisely the
            // failure this guards against escape.
            $this->logger?->critical('Sensitive operation refusal could not be audited', [
                'operation' => $operation,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->forbiddenResponse($request);
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return Response::json(
                ['error' => 'Re-authentication required for this operation', 'status' => 403],
                ResponseStatus::Forbidden->value,
            );
        }

        return new Response(
            statusCode: ResponseStatus::Forbidden->value,
            body: 'Re-authentication required for this operation',
        );
    }
}
