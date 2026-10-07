<?php

declare(strict_types=1);

namespace Pulsar\Auth\Middleware;

use JsonException;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\SecurityContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\DispatchedRouteAwareInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Random\RandomException;
use SodiumException;
use Throwable;

use function str_contains;

/**
 * Route-level middleware that triggers lazy identity resolution and checks authorization.
 *
 * Reads the required permissions from the route the KERNEL is dispatching.
 * Returns 401 if unauthenticated, 403 if permission denied.
 *
 * ## Where this frame sits, measured rather than assumed
 *
 * It is registered as the `auth` middleware ALIAS, so it runs in the route-level
 * pipeline — and {@see \Pulsar\Core\Kernel::dispatchRoute()} wraps the
 * post-routing pipeline INSIDE that one. On a route registered through
 * {@see \Pulsar\Routing\RouteAccessRegistrar::authenticated()} the order is
 * therefore `auth` first, then everything piped post-routing, then the handler.
 * This frame is the outermost authorization decision on such a route, not the
 * innermost, and it is the one an anonymous caller actually meets.
 *
 * ## The permissions come from the dispatch, not from `_route`
 *
 * They used to be read off the `_route` request attribute. Being route-level,
 * this middleware has other route-level middleware in front of it, and any of
 * them can hand the next frame a request carrying whatever `_route` it likes
 * while the kernel goes on dispatching the route it matched. A substitute route
 * declaring `permissions: ['_authenticated']` was therefore a complete RBAC
 * bypass: this frame read the forged list, took the documented "any
 * authenticated user" branch, and the gate was never asked about the real
 * route's permissions at all.
 *
 * There is nothing to compare now. {@see DispatchedRouteAwareInterface} hands
 * the route down as an argument, bound by the pipeline before the chain is built
 * and therefore before any frame that could write an attribute exists. A copy
 * that was never bound has no route and falls into the fail-closed branch, which
 * is where a request with no route context belonged already.
 *
 * ## An anonymous refusal is counted, not chained
 *
 * A caller with no identity is refused here, and that refusal used to be written
 * into the tamper-evident audit chain: one HMAC advance and one `LOCK_EX` append
 * per anonymous request, with the REQUESTED PATH as the resource — attacker-chosen
 * bytes — and no ceiling of any kind. Three things are wrong with that as
 * evidence, and they are the same three wherever an unauthenticated denial is
 * chained:
 *
 *  - **Nothing was accessed.** The refusal is decided from the identity alone,
 *    before any permission is evaluated and before any handler runs, so the entry
 *    is not the record of an access. It is the record of an attempt, which is
 *    what a metric counts.
 *  - **The key space belongs to the caller.** The resource column is the request
 *    path, so the number of distinct entries is the size of the URL space rather
 *    than the size of the route table.
 *  - **Every entry is the same entry.** Same actor (`anonymous`), same action,
 *    same reason. A thousand of them carry exactly the information one of them
 *    carries, plus a number the chain is the wrong place to keep.
 *
 * So an anonymous refusal increments a counter labelled with the route and the
 * reason — both route-table-bounded, neither taken from the request — and the
 * application log gets the occurrence at `debug`, where an enumeration scan
 * cannot fill a disk through it. The access log, which every deployment has and
 * which is built for volume, is where "how many 401s did route R serve" is
 * counted; it was never this chain's question.
 *
 * A DENIAL OF AN IDENTIFIED CALLER IS UNCHANGED: full entry, one per occurrence,
 * no ceiling. Its volume is bounded by the number of credentials, whoever floods
 * it is named in every line, and it is exactly the record an assessor asks for.
 */
final readonly class AuthorizationMiddleware implements DispatchedRouteAwareInterface, MiddlewareInterface
{
    /** Counter name for refusals decided with no authenticated caller. */
    private const string ANONYMOUS_DENIAL_METRIC = 'pulsar_auth_anonymous_denials_total';

    /** Route label for a copy that was never bound to a dispatch. */
    private const string UNROUTED = '(unrouted)';

    public function __construct(
        private GateInterface $gate,
        private ?AuditLogger $auditLogger = null,
        private ?RequestContextHolder $contextHolder = null,
        private ?MetricRegistry $metrics = null,
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
            $this->gate,
            $this->auditLogger,
            $this->contextHolder,
            $this->metrics,
            $this->logger,
            $route,
        );
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SecurityContext|null $securityContext */
        $securityContext = $request->getAttribute('_security_context');

        if ($securityContext === null) {
            // No auth stack reached this request at all. The caller is
            // unidentified for the same reason and gets the same answer as one
            // whose guards said no, counted under its own reason.
            $this->countAnonymousDenial($request, 'no_security_context');

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
            $this->countAnonymousDenial($request, 'unauthenticated');

            return $this->unauthorizedResponse($request);
        }

        // The route the KERNEL is dispatching, handed down by the pipeline.
        // Not `$request->getAttribute('_route')`: see the class docblock.
        $matchedRoute = $this->dispatchedRoute;

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
     * Count a refusal decided with no authenticated caller.
     *
     * The labels are the route and the reason, and both are bounded by the route
     * table: the route comes from {@see forDispatchedRoute()}, which the kernel
     * fills from the table it matched against, and the reason is one of two
     * literals. That bound is the whole point — {@see LabelSet} keys an in-memory
     * map per distinct label combination, so labelling on the request path would
     * rebuild the same unbounded, caller-controlled key space in memory that the
     * chain write had on disk.
     *
     * With no metrics registry wired the counter is absent and the `debug` line
     * is all that remains. Metrics are on by default
     * ({@see \Pulsar\Config\MetricsConfig::$enabled}) and MetricsWiring runs
     * before AuthWiring, so the binding is final by the time this middleware is
     * built — but an operator who turns metrics off loses the count, and the
     * access log every deployment already keeps is what still counts the 401.
     * AuthWiring publishes no {@see \Pulsar\Core\Wiring\Contract\WiringContract},
     * so that degradation is documented rather than reported by the
     * wiring-contract inspector. Saying otherwise would be a claim this code does
     * not support.
     */
    private function countAnonymousDenial(ServerRequestInterface $request, string $reason): void
    {
        $route = $this->dispatchedRoute?->getName()
            ?? $this->dispatchedRoute?->route->path
            ?? self::UNROUTED;

        $this->logger?->debug('Authorization refused an unauthenticated request', [
            'reason' => $reason,
            'route' => $route,
            'path' => $request->getUri()->getPath(),
        ]);

        try {
            $this->metrics?->counter(
                self::ANONYMOUS_DENIAL_METRIC,
                'Requests refused by route authorization with no authenticated caller',
            )->increment(new LabelSet(['route' => $route, 'reason' => $reason]));
        } catch (Throwable $e) {
            // A metric must not be able to turn a refusal into a 500. The
            // registry throws on a name/type collision, which is a wiring
            // mistake in some other component and is not this caller's to
            // resolve mid-request.
            $this->logger?->warning('Anonymous denial counter could not be incremented', [
                'reason' => $reason,
                'exception' => $e,
            ]);
        }
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
