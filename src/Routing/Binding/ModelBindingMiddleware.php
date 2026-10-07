<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use Closure;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\DispatchedRouteAwareInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\ResponseStatus;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Attribute\PublicRoute;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\RouteAccess;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tenancy\TenantContext;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Throwable;

use function class_exists;
use function error_log;
use function is_array;
use function is_string;
use function sprintf;
use function str_contains;

/**
 * PSR-15 middleware that resolves route parameters into domain models.
 *
 * Runs after routing and after every frame that CAN authenticate, which is not
 * the same as after authentication: only the route-level `auth` alias resolves
 * an identity, while the globally piped AuthenticationMiddleware leaves a lazy
 * SecurityContext behind and consults no guard. The identity resolver this
 * middleware is constructed with is what turns the opportunity into an answer
 * under both shapes — see
 * {@see \Pulsar\Core\Wiring\ModelBindingWiring::identityResolver()}.
 *
 * That resolver takes no argument, and the omission is the point: the caller is
 * read from {@see \Pulsar\Auth\AuthenticationState}, the holder the framework's
 * own authentication frame publishes into, never from a request attribute any
 * frame in the pipeline can write. It is the same rule the route obeys two
 * sections down, applied to the other half of an authorization decision.
 *
 * Resolves bound models, enforces authorization based on the configured preset,
 * and attaches resolved models to the request.
 *
 * ## The order the steps run in is a security property
 *
 * The middleware asks {@see ModelBinder::plan()} what the route WOULD bind
 * before it binds anything, decides in {@see decideAuthorization()} everything
 * that can be decided from the route and the caller alone, and only then calls
 * a resolver. A caller who is going to be refused for who they are — no
 * identity under a regulated preset, or an illegal `_without_authorization`
 * opt-out — is refused before a single row is read.
 *
 * Running those checks after binding, which is what this middleware used to do,
 * made every bound route an unauthenticated existence oracle: the row was
 * fetched, and only then was the missing identity noticed, so an anonymous
 * request for an id that exists came back `401` while the same request for an
 * absent id came back `404` — with the query time to match. The plan step is
 * what makes the ordering possible without a regression: it distinguishes a
 * route that binds a model from a route that merely has parameters, so a route
 * that binds nothing is still handed on untouched, whatever the preset.
 *
 * ## Nested routes are authorized level by level
 *
 * The hook used to run over the finished map of resolved models, which on a
 * nested route is one pass too late: {@see ModelBinder} resolves the parent to
 * scope the child, so the parent was read — and, when the child's lookup
 * failed, its absence reported — before any authorization decision existed for
 * it. {@see authorizationGate()} is that hook handed to the binder as a gate it
 * calls on each level as it resolves, so every level that is read has been
 * decided, and a caller refused at the parent never causes the child to be
 * looked up at all.
 *
 * ## What a refusal says, and where it says it
 *
 * A caught {@see ModelBindingException} becomes a status and a reason phrase.
 * Its message reaches the log instead: every factory on that exception names
 * the model class and some name the raw route segment, so the bodies that
 * carried it were reflecting a caller's own input back beside the internal
 * class layout of the application. Logging it is also what finally publishes
 * the diagnosis a scoping refusal writes — which parameters, and the three ways
 * to fix the route — to the operator who can act on it.
 *
 * Every refusal is recorded, and the channel depends on whether the caller has
 * a name. A denial of an IDENTIFIED caller goes into the tamper-evident chain
 * in full, one entry per occurrence, with no ceiling and nothing in front of it
 * ({@see auditDenied()}): a known actor refused a named resource is precisely
 * the record an assessor asks for. A denial decided with NO identity is
 * COUNTED ({@see countAnonymousDenial()}) — it reaches neither the chain nor
 * the filesystem. That method states the three reasons, and none of them is
 * "the chain cannot hold an anonymous actor": it can, and
 * {@see \Pulsar\Audit\AuditActor::anonymous()} is how.
 *
 * A 500 is neither. It is a statement about the DEPLOYMENT — a route whose
 * declarations cannot be served, identically, for every caller and every id —
 * so it is published once, at `error`, by the component that decides it
 * ({@see BindingResolver::resolveForRoute()}, which memoises that decision),
 * and the requests that go on hitting it are counted here rather than
 * reprinted. See {@see handleBindingException()}.
 *
 * What a refusal must not say is whether the row exists. An anonymous caller is
 * refused before any resolver runs, so both ids cost the same `401`; a caller
 * the hook refuses is answered `404`, the same as a caller asking for a row that
 * is not there. Neither can separate an existing record from an absent one — see
 * {@see decideAuthorization()} for the first half and {@see authorizationGate()}
 * for the second.
 *
 * ## The route is taken from the kernel, never from the request
 *
 * Everything this middleware decides is a property of the route: which
 * parameters name a model, which class each resolves to, whether the
 * `_without_authorization` opt-out is declared, whether the route is public,
 * how a nested child is scoped to its parent. It used to read that route from
 * the `_route` request attribute — an ordinary attribute, written once by
 * {@see \Pulsar\Core\Kernel::dispatchRoute()} and then rewritable by every frame
 * between routing and here: route-level middleware, anything piped into the
 * {@see \Pulsar\Http\Middleware\PostRoutingPipeline}, anything an extension
 * PREPENDS to it.
 *
 * The kernel does not read that attribute back. It invokes the handler of the
 * {@see MatchedRoute} it matched and resolves the handler's arguments from that
 * route's parameters. So a rewritten `_route` never changed which handler ran;
 * it changed only what this middleware believed was running — and this
 * middleware's output is a set of models the argument resolver treats as
 * authorized for the handler's entity-typed parameters. Another route's
 * declarations, applied to the real route's parameter names and values, produced
 * a model that was resolved under one route's rules and sealed onto another
 * route's signature: {@see \Pulsar\Routing\Binding\BoundModelArgumentResolver}
 * validates the attestation against the REAL route's parameters, and a forged
 * route that copies those names and values satisfies it.
 *
 * There is nothing to compare now. {@see forDispatchedRoute()} is how the route
 * arrives — the pipeline binds a copy of this middleware to the route the kernel
 * is dispatching, before the chain is built and therefore before any frame that
 * could rewrite an attribute exists. {@see process()} reads that and nothing
 * else, so `_route` is not an input to a binding decision at all. A middleware
 * that was never bound has no route, resolves nothing, and hands the request on
 * exactly as it does for a route with no parameters.
 *
 * The per-handler `#[PublicRoute]` attribute lookup is cached in a
 * static map: route handlers are immutable once registered, so the
 * cache is safe for the entire process lifetime and eliminates the
 * per-request `new ReflectionMethod()` + `new ReflectionClass()`
 * cost (M-2 audit response).
 */
#[Internal(reason: 'Middleware wiring; registered in the middleware pipeline by the composition root')]
final class ModelBindingMiddleware implements MiddlewareInterface, DispatchedRouteAwareInterface
{
    /**
     * Route attribute opting a route into resolving soft-deleted rows.
     *
     * @see buildResolutionContext() for why the opt-in lives on the route.
     */
    private const string INCLUDE_TRASHED_ATTRIBUTE = '_with_trashed';

    /**
     * Where the volume of anonymous denials goes, now that none of it goes to
     * the audit chain.
     *
     * Labelled `(route, reason)` and never with anything the caller chose. A
     * {@see Counter} keys a per-label map in memory, so a path label would
     * rebuild inside the process the same unbounded key space that made the
     * chain write untenable — the defect relocated rather than removed. Both
     * labels are drawn from the route table, which is fixed at boot.
     */
    private const string ANONYMOUS_DENIAL_METRIC = 'pulsar_model_binding_anonymous_denials_total';

    /**
     * Every refusal this middleware serves, by route and status.
     *
     * This is what makes "the diagnosis is published once" an aggregation
     * rather than a suppression: the line stops repeating, the occurrences do
     * not stop being counted, and the count is exported. `status` takes four
     * values and `route` comes from the route table.
     */
    private const string REFUSAL_METRIC = 'pulsar_model_binding_refusals_total';

    /**
     * Headers every refusal built here carries itself.
     *
     * @see errorResponse() for why they travel with the response rather than
     *      being left to a pipeline that may not be wired.
     *
     * @var array<string, string>
     */
    private const array REFUSAL_HEADERS = [
        'Cache-Control' => 'no-store',
        'Vary' => 'Accept',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    ];

    /**
     * Per-handler `#[PublicRoute]` lookup cache keyed by `"Class::method"`.
     *
     * @var array<string, bool>
     */
    private static array $publicRouteCache = [];

    /**
     * The counters, resolved once and held rather than looked up per request.
     *
     * Objects, so `clone` copies the reference and every dispatch increments
     * the same instrument. They belong to the {@see MetricRegistry}, which owns
     * their lifetime: nothing here is request state, and nothing here is
     * registered for reset, because a counter cleared between requests counts
     * nothing.
     *
     * Resolved eagerly, so both series exist at zero from boot. A dashboard can
     * then tell "no anonymous denials" from "this deployment reports none of
     * this", which is the difference between a fact and a gap.
     *
     * Eager also puts the one failure mode where it can be fixed.
     * {@see MetricRegistry::counter()} throws when a name is already registered
     * as another instrument type — a wiring collision between two components,
     * which is nobody's to resolve mid-request. Resolving here raises it during
     * composition, at boot, with the colliding name in the message.
     * {@see Counter::increment()} refuses only a negative value, and nothing
     * below passes one, so the request path has nothing left to throw.
     */
    private readonly ?Counter $anonymousDenialCounter;

    private readonly ?Counter $refusalCounter;

    /**
     * The route the kernel is dispatching, for this dispatch only.
     *
     * Null on the instance the container holds and on every copy nobody bound:
     * it is written exactly once, onto a fresh clone, by
     * {@see forDispatchedRoute()}. That is not a style choice. This middleware is
     * resolved once and reused for the process lifetime, and a persistent worker
     * interleaves Fiber-suspended requests through the same instance — a route
     * stored on the shared object would be another request's route, which is the
     * defect this property exists to make impossible rather than a new way to
     * write it.
     */
    private ?MatchedRoute $dispatchedRoute = null;

    /**
     * @param (Closure(): ?IdentityInterface)|null $identityResolver
     *        Who is calling, asked of the auth stack. Provided by the
     *        composition root, which closes it over the
     *        {@see \Pulsar\Auth\AuthenticationState} holder
     *        {@see \Pulsar\Auth\Middleware\AuthenticationMiddleware} publishes
     *        the request's SecurityContext into. It takes NO ARGUMENT on
     *        purpose: it used to be handed the ServerRequest and read
     *        `_identity`, `identity` and `_security_context` off it, so any
     *        frame in the pipeline could name the caller this middleware then
     *        authorized. A closure with no request parameter cannot be fed one.
     *        Null means no auth stack is composed, so no caller is ever
     *        identified and the preset decides what that means.
     * @param MetricRegistry|null $metrics Where anonymous denials and served refusals are
     *        counted. Null when `observability.metrics.enabled` is off — which
     *        {@see \Pulsar\Core\Wiring\ModelBindingWiring::describeWiring()}
     *        declares as a degraded feature rather than leaving as a silence.
     */
    public function __construct(
        private readonly ModelBinder $binder,
        private readonly ModelBindingConfig $config,
        private readonly AuthorizationHookInterface $authHook,
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?Closure $identityResolver = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?AuditLoggerInterface $auditLogger = null,
        ?MetricRegistry $metrics = null,
    ) {
        $this->anonymousDenialCounter = $metrics?->counter(
            self::ANONYMOUS_DENIAL_METRIC,
            'Requests refused by route model binding with no authenticated caller',
        );
        $this->refusalCounter = $metrics?->counter(
            self::REFUSAL_METRIC,
            'Requests refused by route model binding, by route and status',
        );
    }

    /**
     * Bind a copy of this middleware to the route the kernel is dispatching.
     *
     * The copy is what runs; this instance stays unbound and is reused. See the
     * class docblock for why the route arrives here rather than on the request,
     * and {@see $dispatchedRoute} for why it may not be stored on `$this`.
     */
    #[Override]
    public function forDispatchedRoute(MatchedRoute $route): self
    {
        $bound = clone $this;
        $bound->dispatchedRoute = $route;

        return $bound;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // First, and in front of every early return below: this ends the
        // attestations the previous request minted. Most routes bind nothing and
        // leave through one of those returns, and on exactly those requests an
        // object an earlier request resolved and authorized would otherwise
        // still be sealable onto a handler parameter — the model outlives the
        // request whenever the persistence layer keeps an identity map. See
        // {@see ModelBinder::beginRequest()} and {@see BindingProvenance}.
        $this->binder->beginRequest();

        // The route the KERNEL is dispatching, handed over by the pipeline. Not
        // `$request->getAttribute('_route')`: that is rewritable by every frame
        // between routing and this one, and the kernel never reads it back, so a
        // rewrite decides this middleware's answer for a route that is not being
        // served. Unbound means no kernel dispatch put us here — piped globally,
        // or dispatched by a pipeline that was not told the route — and there is
        // no route to bind for, exactly as for a route with no parameters.
        $matchedRoute = $this->dispatchedRoute;

        if ($matchedRoute === null || $matchedRoute->parameters === []) {
            return $handler->handle($request);
        }

        $identity = $this->resolveIdentity();

        try {
            $planned = $this->binder->plan($matchedRoute);
        } catch (ModelBindingException $refusal) {
            // A scoping refusal is read off the route shape, so it is the same
            // answer for every id and discloses nothing about any of them. It
            // is still route state, and the caller has not been let in yet, so
            // the model-independent gate runs first: a misdeclared route and a
            // well-formed one answer an anonymous caller identically. An empty
            // plan is passed because the refusal is what denied us the real
            // one, and the only thing decideAuthorization() reads it for is the
            // permissive-preset skip log, which describes bindings that are
            // never going to be resolved anyway.
            $refused = $this->decideAuthorization($request, $matchedRoute, [], $identity);

            return $refused instanceof ResponseInterface
                ? $refused
                // Decided by BindingResolver, which memoises it and has already
                // written the diagnosis once. Everything plan() can raise comes
                // from there, so this branch never needs to publish one.
                : $this->handleBindingException($refusal, $request, $matchedRoute, diagnosedAtDecision: true);
        }

        if ($planned === []) {
            // No parameter of this route names a model. There is nothing to
            // resolve and nothing to authorize, so the request is handed on
            // exactly as it would be with this middleware absent from the
            // pipeline — no 401, whatever the preset says.
            return $handler->handle($request);
        }

        $decision = $this->decideAuthorization($request, $matchedRoute, $planned, $identity);

        if ($decision instanceof ResponseInterface) {
            return $decision;
        }

        try {
            $resolved = $this->binder->bindWithMeta(
                $matchedRoute,
                $request,
                $this->buildResolutionContext($matchedRoute, $identity),
                // Whatever decideAuthorization() did not answer with a response:
                // a gate over this caller, or a named exemption that had to
                // prove the declaration it rests on. There is no third value,
                // and in particular no absent one.
                $decision,
            );
        } catch (ModelBindingException $e) {
            // Raised while BINDING: the 404s and the 400, plus the one server
            // error no memo decides. Nothing here has been published yet.
            return $this->handleBindingException($e, $request, $matchedRoute, diagnosedAtDecision: false);
        }

        // Every planned parameter either resolved, was refused, or raised, so
        // this is non-empty whenever the plan was.
        $models = $resolved->models;

        // Attach resolved models to the request
        foreach ($models as $paramName => $model) {
            $request = $request->withAttribute('_model_' . $paramName, $model);
        }
        $request = $request->withAttribute('_bound_models', $models);

        return $handler->handle($request);
    }

    /**
     * Who is calling, or null when nobody is.
     *
     * The request is deliberately not passed on: see the constructor's
     * `$identityResolver` parameter for what reading it off the request cost.
     * The `isAuthenticated()` re-check is not redundant with the resolver's own
     * — the resolver is supplied by the composition root and an application may
     * supply another, so the guarantee this middleware acts on is stated here,
     * where the identity is used.
     */
    private function resolveIdentity(): ?IdentityInterface
    {
        if ($this->identityResolver === null) {
            return null;
        }

        $identity = ($this->identityResolver)();

        if ($identity === null || !$identity->isAuthenticated()) {
            return null;
        }

        return $identity;
    }

    /**
     * Build a ResolutionContext from the current request state.
     *
     * `includeTrashed` is driven by the `_with_trashed` route attribute and by
     * nothing else. Route attributes are written into the route table when the
     * application registers its routes — or baked into the compiled table built
     * from that same source — so no header, query string, cookie or body field
     * can introduce one. That is the whole point of putting the trigger there:
     * soft-deleted rows are hidden deliberately, and a request-supplied flag
     * would let any caller un-hide them on every route at once. Declaring it
     * per route means a restore or archive endpoint sees its deleted rows while
     * the read endpoint next to it still cannot.
     *
     * The opt-in only widens what the resolver may find. Every model it returns
     * still goes through {@see authorizationGate()}, so a route that resolves
     * trashed rows grants no access the same route would not grant to a live one.
     */
    private function buildResolutionContext(MatchedRoute $matchedRoute, ?IdentityInterface $identity): ResolutionContext
    {
        $tenantId = null;
        if ($this->tenantContext?->isResolved() === true) {
            $tenantId = $this->tenantContext->get()->id;
        }

        $subjectId = $identity?->id();

        return new ResolutionContext(
            tenantId: $tenantId,
            subjectId: $subjectId,
            includeTrashed: ($matchedRoute->getAttributes()[self::INCLUDE_TRASHED_ATTRIBUTE] ?? false) === true,
        );
    }

    /**
     * Decide everything about authorization that does not need the model.
     *
     * Three of the four decisions this middleware makes are properties of the
     * route and the caller, not of the row: whether the preset mandates
     * authorization, whether the `_without_authorization` opt-out is legal
     * here, and whether there is an authenticated caller at all. Only the hook
     * needs the model. Deciding the first three here — before
     * {@see ModelBinder::bindWithMeta()} runs, and therefore before any
     * resolver is called — is what keeps a refusal from depending on the data.
     *
     * ## What that is worth
     *
     * These checks used to run after binding, one per resolved model. Under a
     * regulated preset an anonymous request was answered `404` when the id did
     * not exist and `401` when it did, because the row was fetched first and
     * only then was the missing identity noticed. That is an existence oracle
     * on every bound route, readable by anyone, with no credentials — status
     * code, body, and the query time behind them all told the caller whether
     * account 4181 is real. Deciding here, the refusal is produced from the
     * route and the request alone, so the two cases are the same response
     * built by the same instructions.
     *
     * The `_without_authorization` refusal moves for the same reason: a route
     * that declares the opt-out illegally is refused for what it declares, and
     * an anonymous caller no longer separates a `403` from a `404` by picking
     * ids.
     *
     * ## The return type carries the rest of the decision
     *
     * A {@see ResponseInterface} is a refusal that has already been decided; the
     * caller must return it without resolving anything. Anything else is a
     * {@see BindingAuthorization} — the value {@see ModelBinder} cannot bind
     * without — and it is one of exactly three things: a gate over the
     * authenticated caller, the `_without_authorization` opt-out this route
     * declares, or a preset that mandates no authorization at all, which is the
     * posture {@see BindingPreset::Standard} documents.
     *
     * There is no fourth answer and, in particular, no absent one. This method
     * is the only place in the framework that builds an authorization, so
     * "resolved but never decided" cannot be produced by any arrangement of the
     * code: the binder demands the value, and the two exemptions verify their
     * own declarations before they will be constructed at all.
     *
     * ## Both refusals are recorded here, in the channel that fits them
     *
     * They are decided here, so they are recorded here — a denial that leaves
     * no trace is the first thing an assessor asks about, and moving these two
     * in front of the resolver had left them behind the only
     * {@see auditDenied()} call there was.
     *
     * Which channel is decided by whether the caller has a name, and by
     * nothing else. An identified caller's denial is written to the chain in
     * full ({@see auditDenied()}). An unidentified caller's is counted
     * ({@see countAnonymousDenial()}). `unauthenticated` is by definition
     * always the second case; `authorization_bypass_forbidden` is either,
     * depending on who arrived.
     *
     * @param array<string, BindingMeta> $planned The bindings this route will resolve, from {@see ModelBinder::plan()}
     */
    private function decideAuthorization(
        ServerRequestInterface $request,
        MatchedRoute $matchedRoute,
        array $planned,
        ?IdentityInterface $identity,
    ): ResponseInterface|BindingAuthorization {
        $isRegulated = $this->config->isRegulatedPreset();

        // Authorization opt-out, declared as the `_without_authorization`
        // ROUTE ATTRIBUTE — there is no attribute class and no router method
        // for it. A regulated preset forbids bypassing authorization on a
        // route that is not #[PublicRoute]; otherwise the opt-out is honored
        // (public route, or a permissive preset).
        if (($matchedRoute->getAttributes()[BindingAuthorization::WITHOUT_AUTHORIZATION_ATTRIBUTE] ?? false) === true) {
            if (!$isRegulated || $this->isPublicRoute($matchedRoute)) {
                // Legality is this method's half of the question — the preset,
                // and whether the handler is #[PublicRoute]. The declaration
                // itself is re-read by the constructor, which refuses to exempt
                // a route that does not carry the attribute.
                return BindingAuthorization::declaredWithoutAuthorization($matchedRoute);
            }

            $metadata = ['route' => $matchedRoute->getName() ?? '', 'models' => self::plannedClasses($planned)];

            if ($identity !== null) {
                $this->auditDenied($request, $identity->id(), 'authorization_bypass_forbidden', $metadata);
            } else {
                $this->countAnonymousDenial($request, $matchedRoute, 'authorization_bypass_forbidden', $metadata);
            }

            return $this->forbiddenResponse($request);
        }

        if ($identity !== null) {
            return BindingAuthorization::gate($matchedRoute, $this->authorizationGate($request, $identity));
        }

        if ($isRegulated) {
            $this->countAnonymousDenial(
                $request,
                $matchedRoute,
                'unauthenticated',
                ['models' => self::plannedClasses($planned)],
            );

            return $this->unauthorizedResponse($request);
        }

        // Permissive preset: record the skip and hand the models over. The
        // model class and the parameter name come from the plan instead of from
        // the resolved objects — the same two values, read one step earlier. A
        // request whose id turns out not to exist now gets the line too, where
        // the 404 used to preempt it.
        $authorization = BindingAuthorization::unenforcedPreset($matchedRoute, $this->config->preset);

        foreach ($planned as $paramName => $meta) {
            $this->logger?->debug('Model binding authorization skipped: no authenticated identity', [
                'model' => $meta->class,
                'parameter' => $paramName,
                'basis' => $authorization->basis,
            ]);
        }

        return $authorization;
    }

    /**
     * The hook, as the one-model-at-a-time gate {@see ModelBinder} calls.
     *
     * This is the half of the decision that genuinely needs the row: a policy
     * answers "may this caller have THIS record", which cannot be known before
     * the record is read. Handing the binder a gate rather than authorizing the
     * finished map is what keeps "needs the row" from becoming "reads every
     * row": the binder runs this on each level the moment it resolves, and a
     * `false` stops the walk there, so a caller refused at the parent of a
     * nested route never causes the child to be looked up.
     *
     * That ordering closes an existence oracle on the parent. Authorizing
     * afterwards meant a caller with no claim on the parent still got the
     * child's `404` when the parent existed and the parent's when it did not —
     * two different answers, and the hook had run for neither level, because
     * the child's absence threw before the authorization pass began. See
     * {@see ModelBinder::bindWithMeta()}.
     *
     * The metadata is the one that actually produced the binding, so the hook
     * enforces the declared authorization policy: a bare fallback meta would
     * silently downgrade every binding to the hook's default permission
     * ('view'), under-authorizing edit and delete routes.
     *
     * ## The refusal is a 404, and that is the point
     *
     * Ordering cannot close the other half of the disclosure property: a policy
     * needs the row, so the row is read before this answers, and the answer
     * therefore depends on whether the row exists. What ordering cannot close,
     * the ANSWER can. {@see ModelBindingException::authorizationFailed()} carries
     * `404`, the same status a row that is not there produces, so a caller this
     * hook refuses receives what a caller asking for a nonexistent id receives —
     * the same status, the same reason phrase, the same headers, the same body.
     * The `403` it used to carry was an existence oracle for anyone with an
     * account and no entitlement: pick ids, read off which ones are real, and on
     * a nested route learn it about the PARENT too.
     *
     * The cost of the old posture's argument — that a `403` is what an operator
     * needs to debug a refusal — is paid somewhere better: the refusal is
     * recorded here as an audited `policy_denied` naming the actor, the model and
     * the parameter, and the exception message reaches the log through
     * {@see handleBindingException()}. Everything that was in the status code is
     * still written down; none of it is written down where the refused caller can
     * read it.
     *
     * The residue is timing. A refusal reads a row and a miss does not find one,
     * and no framework layer can make a store take the same time for both.
     *
     * @return Closure(object, BindingMeta, string): bool
     */
    private function authorizationGate(ServerRequestInterface $request, IdentityInterface $identity): Closure
    {
        return function (object $model, BindingMeta $meta, string $paramName) use ($request, $identity): bool {
            if ($this->authHook->authorize($identity, $model, $meta)) {
                return true;
            }

            $this->auditDenied($request, $identity->id(), 'policy_denied', [
                'model' => $model::class,
                'parameter' => $paramName,
            ]);

            return false;
        };
    }

    /**
     * Whether this route is declared public, by either of the two places that
     * can say so.
     *
     * `#[PublicRoute]` states it on the handler; {@see RouteAccess::Public} in
     * the route's attributes states it at the registration site, which is where
     * a wiring that owns the route but not the controller has to say it. They
     * mean the same thing and are honoured the same way — a route declared
     * public in one place must not be forced through mandatory authorization
     * because it did not also say so in the other.
     *
     * The route attribute is read BEFORE the handler cache. That cache is keyed
     * by `Class::method`, and two routes may share a handler; caching a
     * per-route answer under a per-handler key would leak one route's
     * declaration onto the other.
     */
    private function isPublicRoute(MatchedRoute $matchedRoute): bool
    {
        if (RouteAccess::of($matchedRoute->route) === RouteAccess::Public) {
            return true;
        }

        /** @var mixed $handler */
        $handler = $matchedRoute->getHandler();
        $handlerInfo = $this->resolveHandlerInfo($handler);

        if ($handlerInfo === null) {
            return false;
        }

        [$class, $method] = $handlerInfo;
        $cacheKey = $class . '::' . $method;

        if (isset(self::$publicRouteCache[$cacheKey])) {
            return self::$publicRouteCache[$cacheKey];
        }

        try {
            // Check method-level attribute first
            $reflectionMethod = new ReflectionMethod($class, $method);
            if ($reflectionMethod->getAttributes(PublicRoute::class) !== []) {
                return self::$publicRouteCache[$cacheKey] = true;
            }

            // Check class-level attribute
            $reflectionClass = new ReflectionClass($class);

            return self::$publicRouteCache[$cacheKey] = $reflectionClass->getAttributes(PublicRoute::class) !== [];
        } catch (ReflectionException) {
            return self::$publicRouteCache[$cacheKey] = false;
        }
    }

    /**
     * Extract controller class and method from a route handler.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveHandlerInfo(mixed $handler): ?array
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            /** @var class-string $class */
            $class = $handler[0];
            return [$class, $handler[1]];
        }

        if (is_string($handler) && class_exists($handler)) {
            /** @var class-string $handler */
            return [$handler, '__invoke'];
        }

        return null;
    }

    /**
     * Turn a caught {@see ModelBindingException} into a response, and write the
     * diagnosis where it belongs.
     *
     * The status is the whole of what the client learns. The message is not:
     * every factory on the exception names the model class, and three of them
     * name the route parameter's raw value as well. A `404` reading
     * `No [App\Domain\Patient] found for [id] = "4181"` hands an unauthenticated
     * scanner the internal class layout of the application and echoes back the
     * segment it just sent — reflected into a body whose Content-Type the old
     * plain-text branch did not even set. Neither belongs in a response; both
     * belong in a log.
     *
     * So the message goes to the logger and the reason phrase goes to the
     * client, which is also how the diagnosis a scoping refusal writes — which
     * parameters, and the three ways to fix the route — reaches an operator.
     *
     * ## The 500 is written down once, not once per request
     *
     * This line used to log every server error at `error`. A 500 out of
     * {@see ModelBinder::plan()} is a permanent property of the route: the
     * refusal is decided from declarations, memoised by
     * {@see BindingResolver::resolveForRoute()} and rethrown unchanged, so the
     * message is byte-identical on every request forever. And the route is
     * reachable anonymously — under a permissive preset outright, and under a
     * regulated one on a `#[PublicRoute]` that declares `_without_authorization`
     * — which made one unauthenticated request cost one `error` line with a
     * stack trace. Measured on a route with an ambiguous type hint: 1,761 bytes
     * and 853 µs per request, uncapped. That is the write amplification the
     * anonymous audit write was removed for, one layer over, and 3.7× the bytes.
     *
     * The diagnosis is therefore published by whoever DECIDES it, at `error`,
     * once, because the decision is computed once
     * ({@see BindingResolver::resolveForRoute()}). `$diagnosedAtDecision` says
     * that has happened, and this method then does not reprint it. Nothing is
     * dropped: every refusal, of every status, increments
     * {@see REFUSAL_METRIC}, so "how often is this broken route being hit" is
     * answerable — it is answered by a number instead of by a stack trace per
     * request.
     *
     * That is not a ceiling and cannot become one. There is no capacity, no
     * claim and no shape map here at all: the deduplication IS the resolver's
     * memo, which must already hold an entry for this route shape before this
     * line can be reached. A key space wide enough to defeat it would exhaust
     * memory in that memo — which holds an exception per key — long before it
     * cost a log line, and no arrangement of it can make a NEW refusal go
     * unreported.
     *
     * A server error the resolver did not decide is still reported in full,
     * every time. Exactly one exists —
     * {@see ModelBindingException::authorizationForAnotherRoute()}, raised when
     * a {@see BindingAuthorization} decided for one route is handed to the
     * binder for another. It is unreachable through the kernel path, because
     * this middleware builds the authorization for the route it is binding; if
     * it ever fires, every occurrence is evidence and none of them is traffic.
     *
     * ## What the per-request line still carries, and what it stops carrying
     *
     * The client errors stay at `debug` for the reason they always did: a `404`
     * is ordinary traffic and an enumeration scan must not fill a disk through
     * this line. An already-diagnosed refusal joins them there — so under any
     * production level it is dropped entirely, and the operator's copy is the
     * one `error` line the decider wrote.
     *
     * It also stops carrying the exception. `$context['exception']` is what a
     * handler renders a stack trace from, and for a refusal decided from
     * declarations that trace is the same four frames on every request while the
     * message repeats a diagnosis already published in full. With `debug`
     * enabled — a development machine, or an operator narrowing something down
     * — the line is then a path and a status rather than 2 KB of duplicate. The
     * refusals raised while BINDING keep theirs: their trace names the resolver
     * call that failed, which differs between them.
     *
     * @param bool $diagnosedAtDecision Whether this refusal came out of the memoised
     *        route decision, which has already written the diagnosis at `error`
     */
    private function handleBindingException(
        ModelBindingException $e,
        ServerRequestInterface $request,
        MatchedRoute $matchedRoute,
        bool $diagnosedAtDecision,
    ): ResponseInterface {
        $status = match ($e->getCode()) {
            404 => ResponseStatus::NotFound,
            403 => ResponseStatus::Forbidden,
            400 => ResponseStatus::BadRequest,
            default => ResponseStatus::InternalServerError,
        };

        $this->refusalCounter?->increment(new LabelSet([
            'route' => self::routeLabel($matchedRoute),
            'status' => (string) $status->value,
        ]));

        $context = [
            'status' => $status->value,
            'path' => $request->getUri()->getPath(),
        ];

        if ($diagnosedAtDecision) {
            $this->logger?->debug('Route model binding refused the request: ' . $e->getMessage(), $context);

            return $this->errorResponse($request, $status);
        }

        $context['exception'] = $e;

        if ($status->isServerError()) {
            $this->logger?->error('Route model binding failed: ' . $e->getMessage(), $context);
        } else {
            $this->logger?->debug('Route model binding refused the request: ' . $e->getMessage(), $context);
        }

        return $this->errorResponse($request, $status);
    }

    /**
     * The route as a metric label: its name, or its pattern when it has none.
     *
     * Never the requested path, and the reason is the one the ledger's resource
     * column had before it. A {@see Counter} keys a map in memory on its label
     * set, so a label the caller chooses is a map the caller sizes. The route
     * table is the only bounded source available here, and it is fixed at boot.
     */
    private static function routeLabel(MatchedRoute $matchedRoute): string
    {
        return $matchedRoute->getName() ?? $matchedRoute->route->path;
    }

    /**
     * The one shape every refusal this middleware produces takes.
     *
     * The body is the reason phrase and nothing else — there is no parameter
     * through which a caller-supplied value or an internal class name could
     * reach it — and the headers are the set the framework already gives an
     * error built where no pipeline is guaranteed to decorate it
     * ({@see \Pulsar\ErrorHandling\ProductionRenderer}), minus the style
     * exception its HTML page needs and plus the `Vary` that
     * {@see \Pulsar\Http\VaryHeader} requires of anything negotiated from a
     * request header.
     *
     * They are a floor, not a duplicate: `SecurityHeadersMiddleware` sits
     * outside this frame and overwrites what it is configured for, and it is
     * configured for none of this when an application has not wired it. What it
     * never sets, on any response, is `Content-Type` or `Cache-Control` — and a
     * refusal that depends on who is asking must not be stored by a shared
     * cache keyed on the URL, while a body served without a type is a body the
     * browser is free to sniff.
     */
    private function errorResponse(ServerRequestInterface $request, ResponseStatus $status): ResponseInterface
    {
        $message = $status->reasonPhrase();

        $response = str_contains($request->getHeaderLine('Accept'), 'application/json')
            ? Response::json(['error' => $message, 'status' => $status->value], $status->value)
            : Response::text($message, $status->value);

        foreach (self::REFUSAL_HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function unauthorizedResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->errorResponse($request, ResponseStatus::Unauthorized);
    }

    private function forbiddenResponse(ServerRequestInterface $request): ResponseInterface
    {
        return $this->errorResponse($request, ResponseStatus::Forbidden);
    }

    /**
     * The model classes a plan would have bound, for the audit record.
     *
     * Read from the plan rather than from resolved objects: the denials that
     * carry this list are decided before anything is resolved, and what an
     * assessor needs from them is what the request was reaching for, not what
     * it got.
     *
     * @param array<string, BindingMeta> $planned
     *
     * @return list<string>
     */
    private static function plannedClasses(array $planned): array
    {
        $classes = [];

        foreach ($planned as $meta) {
            $classes[] = $meta->class;
        }

        return $classes;
    }

    /**
     * Record a denial of an IDENTIFIED caller in the tamper-evident audit chain.
     *
     * Two refusals reach here: the hook's own `false`, and the illegal
     * `_without_authorization` opt-out when somebody is signed in. Both are
     * recorded in full, one entry per occurrence, as
     * {@see AuditEvent::Authorization} denials with `$reason` telling them
     * apart — so "every access this route refused" is one query rather than a
     * join across two event types.
     *
     * Nothing throttles this one, and nothing should. Its volume is bounded by
     * the number of credentials in existence: an actor who floods it is named
     * in every line they add, and is revocable. That is the property an
     * anonymous denial cannot have at any capacity, and the reason the split is
     * drawn at attribution: with an unattributable denial counted rather than
     * chained ({@see countAnonymousDenial()}), this half needs no ceiling, and
     * a ceiling here would be the one thing that could drop a record naming
     * somebody.
     *
     * @param array<string, mixed> $metadata
     */
    private function auditDenied(ServerRequestInterface $request, string $actor, string $reason, array $metadata = []): void
    {
        $this->writeAuditDenial(
            actor: $actor,
            reason: $reason,
            resource: $request->getUri()->getPath(),
            metadata: $metadata,
        );
    }

    /**
     * Count a denial decided with NO authenticated caller. Nothing is written
     * to the audit chain, and nothing is written to disk.
     *
     * ## Why this is not {@see auditDenied()} with a different actor
     *
     * Not because the chain cannot hold one. It can: {@see \Pulsar\Audit\AuditActor}
     * ships an `anonymous()` constructor and {@see \Pulsar\Audit\AuditActorKind} names
     * the kind, so the framework's own position is that an unattributable
     * request is a DECLARED actor kind rather than a missing one. Three other
     * reasons decide it.
     *
     * **Nothing was accessed.** The refusal is reached before
     * {@see ModelBinder::bindWithMeta()}, so no resolver runs and no row is
     * read — measured at zero resolver calls over 11,000 anonymous requests.
     * An audit record of an access that did not happen is not evidence of
     * anything; it is a record of traffic, and traffic is what the access log
     * already counts.
     *
     * **The key space belongs to the caller.** With no ceiling, an entry per
     * request means an unauthenticated caller decides how large the chain
     * grows: one HMAC advance plus one `LOCK_EX` append each, measured at 482
     * bytes and 916 µs against a 26 µs refusal, which is 35× the request it
     * records. That is unbounded write amplification into the one structure
     * whose worth depends on being append-only and verifiable, plus a
     * process-wide serialization point on the sink's lock, available to anyone
     * who can open a socket. A ceiling does not fix it — a ceiling that can be
     * filled is an audit-SUPPRESSION switch, and the one that used to stand
     * here refused every shape once 64 were taken, including the shapes that
     * name somebody.
     *
     * **Every record would be the same record.** Same actor, same action, same
     * reason, same models, differing only in a path the caller chose. Ten
     * thousand of them are not ten thousand facts.
     *
     * ## So what is recorded, and where
     *
     * - **The metric** — {@see ANONYMOUS_DENIAL_METRIC}, labelled by route and
     *   reason — carries the volume. It is in memory, it is bounded by the
     *   route table, and it is what "how many anonymous 401s did route R serve"
     *   was always the right question for.
     * - **The application log** gets every occurrence with the concrete path,
     *   at `debug`, matching {@see handleBindingException()}: an anonymous 401
     *   is ordinary traffic and an enumeration scan must not be able to fill a
     *   disk through this line either.
     * - **The audit chain** gets nothing. An AUTHENTICATED denial still goes
     *   into it in full, one entry per occurrence, no ceiling
     *   ({@see auditDenied()}) — that is the record an assessor asks for, and
     *   splitting on attribution rather than on severity is what lets it have
     *   no ceiling at all.
     *
     * @param array<string, mixed> $metadata
     */
    private function countAnonymousDenial(
        ServerRequestInterface $request,
        MatchedRoute $matchedRoute,
        string $reason,
        array $metadata = [],
    ): void {
        $route = self::routeLabel($matchedRoute);

        $this->anonymousDenialCounter?->increment(new LabelSet([
            'route' => $route,
            'reason' => $reason,
        ]));

        $this->logger?->debug('Model binding refused an unauthenticated request', [
            'reason' => $reason,
            'route' => $route,
            'path' => $request->getUri()->getPath(),
            ...$metadata,
        ]);
    }

    /**
     * Hand one denial to the audit logger, and survive its failure.
     *
     * The chain is HMAC-backed, writes through a locked file, and can fail on
     * its own terms — a full volume, a revoked key, a sink that cannot open. Two
     * things must remain true when it does, and neither used to be.
     *
     * **A failure to record must not become a failure to deny.** The previous
     * catch named `RandomException`, `JsonException` and `SodiumException`,
     * which are the failures of building an entry. The failures of WRITING one
     * — `SecurityException::auditWriteFailed` from
     * {@see \Pulsar\Security\Audit\AuditFileSink::write()} — and of resolving an
     * actor were not in the list, so a full audit volume turned every 401 into
     * an unaudited 500. Everything is caught here; the caller's refusal is
     * already built and is returned regardless.
     *
     * **The failure must not vanish.** The old catch was empty, so an audit
     * subsystem failing on every single denial produced no signal anywhere —
     * the deployment looked healthy and was recording nothing. It is reported
     * at `critical`, which is the level that says the evidentiary guarantee is
     * currently not being met; and when no logger is wired at all it falls back
     * to `error_log()`, the same last-resort channel {@see \Pulsar\Core\Kernel}
     * uses for failures that happen where no logging exists yet. That line
     * carries the exception and the reason, and not the actor: the server error
     * log has no redaction, and a subject identifier does not belong in it.
     *
     * Escalation beyond this — failing the process closed once the chain has
     * been unwritable for long enough — is a posture decision that belongs to
     * the audit subsystem, which knows how long "long enough" is. It is not
     * this middleware's to make per request.
     *
     * @param array<string, mixed> $metadata
     */
    private function writeAuditDenial(string $actor, string $reason, string $resource, array $metadata): void
    {
        try {
            $this->auditLogger?->log(
                event: AuditEvent::Authorization,
                outcome: AuditOutcome::Denied,
                actor: $actor,
                action: 'model_binding_authorization',
                resource: $resource,
                metadata: ['reason' => $reason, ...$metadata],
            );
        } catch (Throwable $e) {
            if ($this->logger !== null) {
                $this->logger->critical('Model binding denial could not be written to the audit chain', [
                    'reason' => $reason,
                    'actor' => $actor,
                    'resource' => $resource,
                    'exception' => $e,
                ]);

                return;
            }

            error_log(sprintf(
                '[Pulsar] Audit write failed for a model-binding denial (reason=%s): %s: %s',
                $reason,
                $e::class,
                $e->getMessage(),
            ));
        }
    }
}
