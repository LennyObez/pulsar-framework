<?php

declare(strict_types=1);

namespace Pulsar\Routing\Binding;

use NoDiscard;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\MatchedRoute;

use function ctype_digit;
use function is_array;
use function is_string;
use function ltrim;

/**
 * Resolves route parameters into domain model instances.
 *
 * Orchestrates the binding pipeline: determines which parameters need
 * model resolution, validates key types, delegates to the appropriate
 * resolver, and supports scoped (parent/child) binding chains.
 *
 * A scoped binding is resolved through the parent that {@see BindingResolver}
 * named for it, not through whichever model resolved most recently, so a child
 * can only ever be checked against the level the route path puts it under.
 *
 * Levels are resolved outside-in and authorized one at a time: the
 * {@see BindingAuthorization} every call takes decides each model before the
 * next lookup starts, so a caller refused at the parent never reaches the child
 * and never learns whether it exists.
 *
 * ## The authorization is an argument, and it has no default
 *
 * It used to be `?Closure $authorize = null`, and every resolved model was
 * minted into {@see BindingProvenance} whether or not a gate had run. Provenance
 * is the evidence {@see BoundModelArgumentResolver} seals a handler argument on,
 * so an attestation produced without a decision let an unauthorized object reach
 * a handler parameter typed as an entity — undisplaceable, and carrying the same
 * evidence an authorized one carries. {@see BindingAuthorization} is what
 * removes the default: there is no value for that parameter that does not say
 * what decided this route, and a model is minted only once it has answered.
 *
 * ## This class is not a container service, and that is not the protection
 *
 * {@see \Pulsar\Core\Wiring\ModelBindingWiring} constructs one binder and hands
 * it to {@see ModelBindingMiddleware} as a private property, and does not
 * register it. That removes the shortest mint — `get(ModelBinder::class)` then
 * `bindWithMeta()` — and it is worth removing. It is not a boundary:
 * `Closure::bind()` reads a private property of any reachable object, and the
 * middleware holding this one is itself reachable. What makes a reachable mint
 * useless is that {@see BindingProvenance} credits an entry only for the pass
 * that is open and the {@see MatchedRoute} the kernel presents at the handler
 * frame, so a model minted under a route of the caller's own choosing attests to
 * nothing. See that class for the full statement.
 * @api
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class ModelBinder
{
    /**
     * @param BindingProvenance|null $provenance
     *        Records every model this binder produces, so that the argument
     *        resolver can tell an object the framework resolved from one a
     *        request attribute merely names. Composed by
     *        {@see \Pulsar\Core\Wiring\ModelBindingWiring}, which hands the same
     *        instance to {@see BoundModelArgumentResolver}. Null means nothing
     *        is recorded — a binder built by hand for a single call — and a
     *        resolver holding a different instance then seals nothing, which is
     *        the fail-closed direction.
     */
    public function __construct(
        private ModelResolverPort $defaultResolver,
        private BindingResolver $bindingResolver,
        private ContainerInterface $container,
        private ?BindingProvenance $provenance = null,
    ) {}

    /**
     * Open the binding pass this request's attestations belong to.
     *
     * Every attestation minted before this call stops attesting, which is what
     * confines a seal to the request that earned it. It is called from the top
     * of {@see ModelBindingMiddleware::process()} rather than from
     * {@see bindWithMeta()}, and the difference matters: most routes bind
     * nothing, the binder is never reached on them, and a pass opened only where
     * models are resolved would leave the previous request's attestations
     * standing for every one of those requests — which is exactly where an
     * object held alive by a process-lifetime identity map would be replayed.
     *
     * @see BindingProvenance for why the pass is a private counter rather than
     *      anything carried on the request.
     */
    public function beginRequest(): void
    {
        $this->provenance?->beginRequest();
    }

    /**
     * Resolve all model bindings for a matched route.
     *
     * @param BindingAuthorization $authorization Decides each level as it resolves; a refusal stops the walk there
     *
     * @return array<string, object> Resolved models keyed by parameter name
     *
     * @throws ModelBindingException When a model cannot be found, key validation fails, or a level is refused
     */
    #[NoDiscard]
    public function bind(
        MatchedRoute $matchedRoute,
        ServerRequestInterface $request,
        ResolutionContext $context,
        BindingAuthorization $authorization,
    ): array {
        return $this->bindWithMeta($matchedRoute, $request, $context, $authorization)->models;
    }

    /**
     * Resolve all model bindings for a matched route, returning both the
     * resolved models and the {@see BindingMeta} that produced each one.
     *
     * The metadata comes back because it is what produced each model — the
     * declared key, scope and `authzPolicy` — and a caller inspecting the
     * result should be able to see the declaration behind it without a second
     * (uncached) reflection / compiled-map lookup on the request hot path.
     *
     * ## Every level is authorized as it resolves
     *
     * `$authorization` decides each model the moment it is resolved and BEFORE
     * the next level is looked up. On a refusal the binder throws and no deeper
     * level is read. That ordering is the whole contract of the parameter,
     * because a nested route resolves its levels outside-in: the parent is
     * fetched to scope the child, so the parent is read first and
     * unconditionally.
     *
     * Authorizing afterwards, over the finished map, is what this used to do
     * and it made every nested route an existence oracle on its parent. A
     * caller with no claim on `/records/{record}` asked for
     * `/records/{record}/entries/{entry}`: the record was fetched, the entry was
     * looked up inside it, and the request failed with a `404` naming the ENTRY
     * — which only happens when the record exists. The same request against a
     * record that does not exist failed one step earlier, and the two answers
     * differed. The hook, meanwhile, had never run at all: the throw came from
     * the child's lookup, before the authorization pass the middleware would
     * have made over both levels. So the parent's existence was disclosed by a
     * request the framework had not yet decided anything about.
     *
     * The gate inside the authorization takes the model, the metadata that
     * bound it and the parameter name, and answers a single bool. It does not
     * see the request, the identity or the response: deciding who the caller is,
     * recording the denial and choosing a status code all belong to the
     * middleware, and the binder's only job is to stop.
     *
     * ## The decision is pinned to this match
     *
     * A {@see BindingAuthorization} names the route it was decided about, and a
     * decision about another route is refused here rather than honoured. The two
     * exemptions it can carry are legally obtainable — a route that declares the
     * opt-out, a preset that mandates nothing — and without this check either
     * one would be a value that binds ANY route without a policy, which is a
     * bearer token for skipping authorization.
     *
     * @param BindingAuthorization $authorization Decides each level as it resolves; a refusal stops the walk there
     *
     * @throws ModelBindingException When a model cannot be found, key validation fails, a level is
     *                               refused, or the authorization was decided for another route
     */
    #[NoDiscard]
    public function bindWithMeta(
        MatchedRoute $matchedRoute,
        ServerRequestInterface $request,
        ResolutionContext $context,
        BindingAuthorization $authorization,
    ): ResolvedBindings {
        if (!$authorization->appliesTo($matchedRoute)) {
            throw ModelBindingException::authorizationForAnotherRoute(
                $matchedRoute->getName() ?? $matchedRoute->route->path,
            );
        }

        $planned = $this->plan($matchedRoute);

        if ($planned === []) {
            return new ResolvedBindings([], []);
        }

        $resolved = [];
        $resolvedMetas = [];

        foreach ($planned as $paramName => $meta) {
            // {@see plan()} has already dropped every parameter the match did
            // not supply. Reading it again is what turns `?string` into the
            // `string` the coercion below takes, and it reads the same map.
            $rawValue = $matchedRoute->parameter($paramName);
            if ($rawValue === null) {
                continue;
            }

            $keyValue = $this->coerceKeyValue($paramName, $meta, $rawValue);
            $resolver = $this->getResolver($meta);

            if ($meta->scoped) {
                // The parent is the one the route path named, looked up by
                // name rather than taken from whatever resolved last. A level
                // that did not resolve — an optional placeholder with no value,
                // say — therefore stops its children instead of handing them
                // its own parent to be checked against.
                $parent = $meta->parentParameter === null ? null : ($resolved[$meta->parentParameter] ?? null);

                if ($parent === null || $meta->parentRelation === null) {
                    throw ModelBindingException::unresolvedParent($paramName, $meta->parentParameter ?? '?');
                }

                $model = $resolver->resolveScoped(
                    $meta->class,
                    $meta->keyName,
                    $keyValue,
                    $parent,
                    $meta->parentRelation,
                    $context,
                );
            } else {
                $model = $resolver->resolve(
                    $meta->class,
                    $meta->keyName,
                    $keyValue,
                    $context,
                );
            }

            if ($model === null) {
                throw ModelBindingException::modelNotFound($meta->class, $meta->keyName, $rawValue);
            }

            // Before the next level is looked up, before this one is recorded
            // as a parent anything else may resolve through, and before it is
            // minted: a model the decision refused must reach no part of the
            // request, provenance included.
            if (!$authorization->permits($model, $meta, $paramName)) {
                throw ModelBindingException::authorizationFailed($meta->class, $keyValue);
            }

            $resolved[$paramName] = $model;
            $resolvedMetas[$paramName] = $meta;

            // The one place every resolved model passes through, custom
            // per-binding resolvers included, and it is downstream of the line
            // above: an attestation exists only for a model an authorization
            // decision has passed. Recording here — rather than where the models
            // are attached to the request — is what lets the argument resolver
            // seal on the strength of what the framework produced instead of on
            // the strength of an attribute anything in the pipeline can write.
            //
            // $matchedRoute goes into the record because the parameter name and
            // the raw value are precisely what a forged match copies. The route
            // is what it cannot: the kernel restores its own MatchedRoute at the
            // handler frame, and that is the object the seal is checked against.
            $this->provenance?->record($model, $matchedRoute, $paramName, $rawValue);
        }

        return new ResolvedBindings($resolved, $resolvedMetas);
    }

    /**
     * What this route WOULD bind, decided without resolving any of it.
     *
     * Answers one question — which parameters of this match name a model, and
     * under which metadata — from route-table data alone: the compiled map, the
     * handler signature, the explicit bindings and the route path. No resolver
     * is consulted, so nothing here reaches a database, a cache or any other
     * store, and the answer is the same for every caller and every parameter
     * VALUE the route can match.
     *
     * That last property is the point. Every decision route model binding makes
     * before the hook runs — is authorization mandatory here, is there an
     * authenticated caller, is the `_without_authorization` opt-out legal on
     * this route — is answerable from this plan plus the request, and none of
     * them needs the model. {@see \Pulsar\Routing\Binding\ModelBindingMiddleware}
     * therefore asks for the plan first and settles those before it calls
     * {@see bindWithMeta()}: a caller who is going to be refused with a 401 is
     * refused before a single row is read, so the refusal cannot be told apart
     * from the refusal a nonexistent id would have produced.
     *
     * The plan is what {@see bindWithMeta()} itself iterates, so the two cannot
     * disagree about whether a route binds anything — a disagreement in one
     * direction reintroduces exactly that disclosure, and in the other refuses
     * a route that binds nothing at all.
     *
     * Parameters the match did not supply are dropped here rather than skipped
     * later: an optional placeholder the URL omitted binds nothing, and a plan
     * that counted it would claim the route resolves a model it never asks for.
     *
     * Asking for the plan and then binding reads the memoised metadata twice —
     * one extra {@see BindingResolver::resolveForRoute()} hit per bound request,
     * around a microsecond on a warm process. Handing the plan back in to be
     * bound would save it and would put a plan for one route within reach of
     * another route's resolution, which is not a trade this class makes.
     *
     * @return array<string, BindingMeta> Ordered by path position, keyed by parameter name
     *
     * @throws ModelBindingException When the route path asserts a containment that cannot be checked
     */
    #[NoDiscard]
    public function plan(MatchedRoute $matchedRoute): array
    {
        /** @var mixed $handler */
        $handler = $matchedRoute->getHandler();
        $handlerInfo = $this->resolveHandlerInfo($handler);

        if ($handlerInfo === null) {
            return [];
        }

        [$controllerClass, $controllerMethod] = $handlerInfo;

        $bindingMetas = $this->bindingResolver->resolveForRoute(
            $matchedRoute,
            $controllerClass,
            $controllerMethod,
        );

        $planned = [];

        foreach ($bindingMetas as $paramName => $meta) {
            if ($matchedRoute->parameter($paramName) === null) {
                continue;
            }

            $planned[$paramName] = $meta;
        }

        return $planned;
    }

    /**
     * Validate and coerce the raw route parameter value to the declared key type.
     */
    private function coerceKeyValue(string $paramName, BindingMeta $meta, string $rawValue): string|int
    {
        if ($meta->keyType === 'int') {
            // Strict integer validation: must be a string of digits, optionally with leading sign
            if (!ctype_digit($rawValue) && !ctype_digit(ltrim($rawValue, '-'))) {
                throw ModelBindingException::invalidKeyType($paramName, 'int', $rawValue);
            }

            // Reject negative values for model keys
            if ($rawValue[0] === '-') {
                throw ModelBindingException::invalidKeyType($paramName, 'int', $rawValue);
            }

            return (int) $rawValue;
        }

        return $rawValue;
    }

    /**
     * Determine the appropriate resolver for a binding.
     */
    private function getResolver(BindingMeta $meta): ModelResolverPort
    {
        if ($meta->customResolver !== null) {
            /** @var ModelResolverPort */
            return $this->container->get($meta->customResolver);
        }

        return $this->defaultResolver;
    }

    /**
     * Extract controller class and method from a route handler.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveHandlerInfo(mixed $handler): ?array
    {
        // Array handler: [ControllerClass::class, 'method']
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0]) && is_string($handler[1])) {
            /** @var class-string $class */
            $class = $handler[0];
            return [$class, $handler[1]];
        }

        // String handler: invokable controller class
        if (is_string($handler) && class_exists($handler)) {
            /** @var class-string $handler */
            return [$handler, '__invoke'];
        }

        // Closures and other callables cannot be reflected for type hints
        return null;
    }
}
