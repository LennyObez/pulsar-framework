<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Closure;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\AuthenticationState;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Config\CallableConfigLoader;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Boot\DeferredComposition;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\OptionalBinding;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\PostRoutingPipeline;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Binding\BindingProvenance;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BoundModelArgumentResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\PolicyAuthorizationHook;
use Pulsar\Routing\Router;
use Pulsar\Tenancy\TenantContext;

/**
 * Composes route model binding: the pipeline that turns `/users/{user}` into a
 * loaded, authorized, tenant-scoped User on the controller signature.
 *
 * ## Nothing here is on by default, and absence costs one has() call
 *
 * Model resolution needs a persistence adapter, and the framework core must not
 * know that any ORM exists. So the whole assembly is gated on a
 * {@see ModelResolverPort} binding, which only a persistence extension provides.
 * An application without one pays exactly one {@see ContainerInterface::has()}
 * at boot: no middleware is piped, no argument resolver joins the kernel chain,
 * and the request path is byte-identical to the one it takes today.
 *
 * ## Why the gate is deferred rather than evaluated in wire()
 *
 * Both facts this wiring depends on are FALSE at `wire()` time and true later:
 *
 *  - `ModelResolverPort` is bound by an extension's `register()`, which the
 *    kernel runs after every wiring has finished. A `has()` test inside `wire()`
 *    answers "no" even with the ORM installed — the feature would be permanently
 *    off in exactly the deployment that asked for it.
 *  - `Router::$explicitBindings` is filled by `Router::model()` calls in
 *    routes/web.php and routes/api.php, which the kernel loads after every
 *    wiring has finished. A {@see BindingResolver} built in `wire()` captures an
 *    empty list, and every explicit binding an application declared is silently
 *    ignored in favour of implicit type-hint resolution.
 *
 * Both are the same defect class this wiring exists to remove, so the decision
 * is registered with {@see DeferredComposition} and evaluated at the END of
 * boot, against the final container and the final route table.
 *
 * ## Where the middleware runs, and why it cannot run anywhere else
 *
 * It is piped into the {@see PostRoutingPipeline}, not the global one. The
 * global pipeline runs BEFORE routing — its innermost handler is the kernel's
 * dispatch, and the route is decided inside that dispatch. Piped globally,
 * {@see ModelBindingMiddleware::process()} would see a null route on its first
 * line and return, and the feature would be as inert as it is when nothing pipes
 * it at all. The post-routing pipeline is the innermost frame before the
 * handler, which is simultaneously after routing and after every frame that can
 * authenticate: the globally piped {@see AuthenticationMiddleware} and the
 * route-level `auth` alias.
 *
 * What the middleware gets from being piped THERE is the route itself, and it
 * gets it as an argument rather than from the request. The post-routing pipeline
 * is the one the kernel hands the dispatched {@see \Pulsar\Routing\MatchedRoute}
 * to, and it binds every
 * {@see \Pulsar\Http\Middleware\DispatchedRouteAwareInterface} middleware to it
 * before building the chain. The `_route` attribute is not that channel: it is
 * rewritable by everything between routing and here — including anything
 * prepended to this same pipeline — while the kernel goes on invoking the
 * handler it matched, so a middleware reading the attribute could be resolving
 * and sealing models under a route that is not being served.
 *
 * "After every frame that can authenticate" is not the same as "after
 * authentication", and the difference is a bug this wiring used to ship. Only
 * the `auth` alias authenticates; the global middleware defers to a lazy
 * {@see SecurityContext} and leaves an {@see AnonymousIdentity} behind until
 * someone asks. Position alone therefore guarantees the OPPORTUNITY to know the
 * caller, never the answer.
 *
 * ## The caller is taken from the auth stack, never from a request attribute
 *
 * What turns the opportunity into an answer is {@see AuthenticationState}: the
 * holder {@see AuthenticationMiddleware} publishes the request's
 * {@see SecurityContext} into. The identity resolver handed to the middleware
 * below is a closure over THAT, and it takes no argument at all — so no request
 * attribute is an input to it, by signature and not by convention.
 *
 * It used to read `_identity`, then `identity`, then `_security_context`, in
 * that order, and take the first authenticated thing it found. All three are
 * PSR-7 attributes: an application middleware, an extension, a route middleware
 * alias, anything prepended to the post-routing pipeline could write one and
 * thereby decide what the authorization hook was asked about. That is the same
 * defect as trusting `_route` for the route — the one the class docblock of
 * {@see ModelBindingMiddleware} spends a section on — arriving one field over.
 *
 * The two shapes are still both covered, because the holder is what BOTH leave
 * behind: the globally piped middleware publishes a lazy context and consults
 * no guard, and the `auth` alias resolves that same context, so an
 * `auth`-guarded bound route authenticates once and an unguarded one
 * authenticates exactly when a bound model needs a subject. Going to the
 * {@see \Pulsar\Auth\AuthManagerInterface} directly instead would double the
 * session read or the token verification on every guarded bound route, because
 * the manager does not memoise and the context is the memo.
 *
 * With no auth stack composed there is no holder, no resolver, and every caller
 * is unidentified — which under the regulated preset means every bound route
 * refuses. That is the correct answer and a silent one, so it is declared as an
 * optional binding in {@see describeWiring()} and reported as a degraded
 * feature rather than discovered as a 401 storm.
 *
 * ## No authorization hook means no binding, deliberately
 *
 * The default hook is {@see PolicyAuthorizationHook}, which needs a
 * {@see GateInterface}; AuthWiring binds one only when config/security.php
 * carries an `auth` section. With no Gate and no `authorization_hook` named in
 * config/model_binding.php, this wiring binds NO hook and the pipeline does not
 * compose.
 *
 * The alternative — a fallback hook that returns true — is the trap this
 * codebase is built to avoid. `AuthorizationMiddleware` default-denies: a route
 * guarded by the `auth` alias that declares no permission is refused rather than
 * allowed, because a configuration gap must never read as a grant. A permissive
 * binding hook sitting one frame away would hand every authenticated caller
 * every model a route names, while the security posture, the docs and the route
 * attributes all still said the route was authorized. Refusing to compose is
 * loud in the only way that matters — the models never reach the controller —
 * and the missing Gate is reported as a degraded feature by the
 * wiring-contract inspector.
 *
 * Owns config/model_binding.php: its loader builds {@see ModelBindingConfig}
 * into the ConfigRepository during config load, so `wire()` resolves it back
 * from the single source of truth rather than reading the file itself.
 */
#[Internal]
final readonly class ModelBindingWiring implements ServiceWiringInterface, DescribesWiring, ProvidesConfigLoaders
{
    #[Override]
    public function configLoaders(): array
    {
        return [
            'model_binding' => new CallableConfigLoader(
                ModelBindingConfig::class,
                static fn(array $data): object => ModelBindingConfig::fromArray($data),
            ),
        ];
    }

    #[Override]
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'model-binding',
            configClass: ModelBindingConfig::class,
            configFile: 'model_binding.php',
            // AuthorizationHookInterface is deliberately absent: it is bound only
            // when a Gate or a configured hook class exists, and `provides` means
            // "this wiring always registers it". Declaring a conditional binding
            // here would let another wiring's `requires` be judged satisfied by a
            // binding that is not there.
            provides: [ModelBindingConfig::class],
            // The three seams the composition root owns. They are bound by the
            // Kernel before any wiring runs, so they are always present in a real
            // boot — and if a composition root ever stops binding one, the
            // wiring-contract gate fails on the full boot graph instead of this
            // feature disappearing without a trace.
            requires: [
                DeferredComposition::class,
                PostRoutingPipeline::class,
                ArgumentResolverRegistryInterface::class,
            ],
            // None of these carry security: true, and the reason is boot order,
            // not indifference. SecurityPostureWiring evaluates the degraded-
            // feature set from inside the wiring loop — before extension
            // register() has run — so ModelResolverPort is unbound at that moment
            // in EVERY application, ORM installed or not. A security-flagged
            // entry would therefore raise a posture FAIL universally and, with
            // enforcement on in production, abort the boot of applications that
            // are correctly configured. The genuinely dangerous state — binding
            // composed but authorization absent — is unreachable by construction:
            // with no hook, nothing composes.
            optional: [
                new OptionalBinding(
                    binding: ModelResolverPort::class,
                    feature: 'route model binding (route parameters resolved into domain models)',
                    fix: 'Install a persistence extension that binds ModelResolverPort, e.g. pulsar/orm.',
                ),
                new OptionalBinding(
                    binding: GateInterface::class,
                    feature: 'authorization of bound models, without which route model binding does not compose at all',
                    fix: 'Configure the `auth` section of config/security.php so AuthWiring binds a Gate, or name your own hook under `authorization_hook` in config/model_binding.php.',
                ),
                // Not security-flagged, for the same boot-order reason as the
                // rest: SecurityPostureWiring evaluates the degraded set from
                // inside the wiring loop, and an application with no `auth`
                // section already reports the GateInterface entry above — with
                // no Gate and no configured hook, nothing composes at all, so
                // this state is only reachable when a hook was named in
                // config/model_binding.php without an auth stack behind it.
                new OptionalBinding(
                    binding: AuthenticationState::class,
                    feature: 'identification of the caller a bound model is authorized for',
                    fix: 'Configure the `auth` section of config/security.php so AuthWiring pipes AuthenticationMiddleware and binds the state it publishes into. Without it no caller is ever identified, so a regulated preset refuses every bound route and a permissive one authorizes none.',
                ),
                new OptionalBinding(
                    binding: TenantContext::class,
                    feature: 'tenant-scoped model resolution',
                    fix: 'Enable tenancy in config/tenancy.php so TenancyWiring binds a TenantContext.',
                ),
                // An anonymous denial writes nothing to the audit chain — it is
                // counted, and this is what counts it. With metrics off the
                // count is gone and only the `debug` line and the access log
                // remain, which is an acceptable posture and an unacceptable
                // surprise. Declaring it here is what makes the difference:
                // the deployment is told it is not reporting this, rather than
                // finding out when somebody asks how many 401s a route served.
                new OptionalBinding(
                    binding: MetricRegistry::class,
                    feature: 'counts of anonymous binding denials and of every refusal served, by route and reason',
                    fix: 'Set observability.metrics.enabled in config/observability.php so MetricsWiring binds a MetricRegistry.',
                ),
                // The fix line used to read "Run `pulsar optimize` to compile the
                // binding map". That command compiles routes and container
                // hints and writes no binding map — no producer for this class
                // ships at all — so an operator following it got no map, no
                // error, and no way to tell the difference.
                new OptionalBinding(
                    binding: CompiledBindingMap::class,
                    feature: 'binding metadata decided without reflecting the controller even once',
                    fix: 'Bind a CompiledBindingMap built from your route table. Nothing in the framework builds one today; without it BindingResolver reflects each route shape once per process and memoises the answer, which costs a persistent worker one decision per route and costs PHP-FPM one per request.',
                ),
            ],
        );
    }

    #[Override]
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        // Published unconditionally, even when the pipeline never composes: the
        // ORM adapter reads this instance in preference to its own extension
        // section, so the two halves of the feature read one file. They enforce
        // different keys of it — this middleware enforces `preset`, the resolver
        // enforces `allowed_key_names` before it builds SQL — which is exactly
        // why a second copy would be a different policy rather than a duplicate.
        //
        // An absent config/model_binding.php yields the DTO's own documented
        // defaults rather than a second, divergent set written here. The DTO owns
        // the no-file posture; inventing one at this seam would put the shipped
        // default in two places that could disagree.
        //
        // That posture is {@see ModelBindingConfig::DEFAULT_PRESET}, which is
        // regulated. This line used to reach a permissive one: the shipped file
        // said 'banking' and the constructor said 'standard', so an application
        // that deleted the file — or never published the section — ran with
        // authorization opt-in while every document describing it said
        // otherwise. Nothing here decides that any more; the DTO does, and a
        // test pins its answer to the shipped file's.
        $config = $repository->has(ModelBindingConfig::class)
            ? $repository->get(ModelBindingConfig::class)
            : new ModelBindingConfig();

        $container->instance(ModelBindingConfig::class, $config);

        $this->bindAuthorizationHook($container, $config);

        // The composition-root seams. Resolving them with get() alone would be a
        // trap: an unbound id naming an instantiable class is autowired, so a
        // composition root that failed to bind these would hand back a fresh,
        // orphaned pipeline and a fresh, orphaned queue — the middleware piped
        // into an object the kernel never dispatches, which is the silent failure
        // this wiring exists to eliminate. They are declared in requires(), so
        // their absence fails the boot-graph gate loudly rather than here.
        if (
            !$container->has(DeferredComposition::class)
            || !$container->has(PostRoutingPipeline::class)
            || !$container->has(ArgumentResolverRegistryInterface::class)
        ) {
            return;
        }

        /** @var DeferredComposition $deferred */
        $deferred = $container->get(DeferredComposition::class);

        $deferred->whenBound(
            ModelResolverPort::class,
            function (ContainerInterface $resolved) use ($router): void {
                $this->compose($resolved, $router);
            },
        );
    }

    /**
     * Bind the hook that approves every resolved model, when one can exist.
     *
     * Three cases, in precedence order: an application that already bound the
     * interface keeps its own; a hook named in config wins next; the Gate-backed
     * default last. A fourth case — no Gate, no configured hook — binds nothing,
     * and the class docblock says why that is the right answer.
     */
    private function bindAuthorizationHook(ContainerInterface $container, ModelBindingConfig $config): void
    {
        if ($container->has(AuthorizationHookInterface::class)) {
            return;
        }

        $configured = $config->authorizationHook;

        if ($configured !== null) {
            $container->bind(AuthorizationHookInterface::class, $configured);

            return;
        }

        if (!$container->has(GateInterface::class)) {
            return;
        }

        // bind(), not instance(): the Gate is resolved only if the composition
        // actually happens, and an extension registering later may still replace
        // the hook with its own before the deferred step reads it.
        $container->bind(
            AuthorizationHookInterface::class,
            static function () use ($container): AuthorizationHookInterface {
                /** @var GateInterface $gate */
                $gate = $container->get(GateInterface::class);

                return new PolicyAuthorizationHook($gate);
            },
        );
    }

    /**
     * Assemble the pipeline. Runs at the end of boot, with a ModelResolverPort
     * bound, a final container and a final route table.
     */
    private function compose(ContainerInterface $container, Router $router): void
    {
        // No hook, no binding. Resolving models and skipping the policy check
        // would be a silent authorization bypass on every bound route.
        if (!$container->has(AuthorizationHookInterface::class)) {
            return;
        }

        /** @var ModelResolverPort $port */
        $port = $container->get(ModelResolverPort::class);

        /** @var ModelBindingConfig $config */
        $config = $container->get(ModelBindingConfig::class);

        /** @var AuthorizationHookInterface $authHook */
        $authHook = $container->get(AuthorizationHookInterface::class);

        $compiledMap = null;
        if ($container->has(CompiledBindingMap::class)) {
            /** @var CompiledBindingMap $compiledMap */
            $compiledMap = $container->get(CompiledBindingMap::class);
        }

        // One provenance record, written by the binder and read by the argument
        // resolver. Composing both halves from a single instance HERE is what
        // makes the seal mean something: an object reaches a handler parameter
        // typed as an entity only if this binder produced it for that parameter
        // and that URL value, so setting the `_bound_models` attribute is not by
        // itself enough to get a value sealed onto a controller signature.
        //
        // Neither this record nor the binder that writes it is bound in the
        // container. That removes the shortest mint and nothing more: the
        // middleware holding them IS bound below, `Closure::bind()` reads a
        // private property of anything reachable, and a pipeline that is itself
        // container-bound hands its middleware back through `snapshot()`. What
        // makes a reachable mint useless is on the record itself — an entry is
        // credited only for the pass that is open and the MatchedRoute the
        // kernel presents at the handler frame, so a model minted under a route
        // of the caller's own choosing attests to nothing. See
        // {@see BindingProvenance}.
        $provenance = new BindingProvenance();

        $logger = null;
        if ($container->has(LoggerInterface::class)) {
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);
        }

        // The logger reaches the RESOLVER, not only the middleware, and that is
        // deliberate. A refusal the resolver decides is a route that cannot be
        // served for anybody; it is computed once per route shape and replayed
        // afterwards, so announcing it from there is one line per broken route
        // instead of one line per request that hits one. See
        // {@see BindingResolver::resolveForRoute()}.
        //
        // $router->explicitBindings is read HERE, at the end of boot, for the
        // reason the class docblock gives: Router::model() runs from the project
        // route files, which load after every wiring.
        $binder = new ModelBinder(
            $port,
            new BindingResolver($router->explicitBindings, $compiledMap, $logger),
            $container,
            $provenance,
        );

        $tenantContext = null;
        if ($container->has(TenantContext::class)) {
            /** @var TenantContext $tenantContext */
            $tenantContext = $container->get(TenantContext::class);
        }

        $auditLogger = null;
        if ($container->has(AuditLoggerInterface::class)) {
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);
        }

        // Bound by MetricsWiring, which runs earlier in the wiring list and is
        // gated on observability.metrics.enabled. This composition runs from
        // DeferredComposition at the end of boot, so the answer here is final.
        $metrics = null;
        if ($container->has(MetricRegistry::class)) {
            /** @var MetricRegistry $metrics */
            $metrics = $container->get(MetricRegistry::class);
        }

        $bindingMiddleware = new ModelBindingMiddleware(
            $binder,
            $config,
            $authHook,
            $tenantContext,
            self::identityResolver($container),
            $logger,
            $auditLogger,
            $metrics,
        );

        // Registered so that "did the pipeline compose" is answerable — the
        // wiring-contract inspector and the boot graph both ask it — and NOT as
        // a security boundary. Resolving this middleware gets a caller
        // `forDispatchedRoute()` and `process()`, both public because
        // DispatchedRouteAwareInterface and PSR-15 require them to be, and
        // unregistering it would change nothing: PostRoutingPipeline is
        // container-bound and its `snapshot()` hands back everything piped into
        // it. Hiding the instance was tried and defeated one call deeper each
        // time, which is why the mint is now closed where it can be — on
        // {@see BindingProvenance}, which credits an entry only for the pass the
        // kernel opened and the route the kernel is dispatching.
        $container->instance(ModelBindingMiddleware::class, $bindingMiddleware);

        /** @var PostRoutingPipeline $postRouting */
        $postRouting = $container->get(PostRoutingPipeline::class);
        $postRouting->pipe($bindingMiddleware);

        // Piping the middleware resolves and authorizes the models; it does not
        // deliver them. Without this the handler still receives the raw string
        // route parameter and a controller type-hinting the entity dies with a
        // TypeError — the middleware would look wired and every bound route
        // would 500.
        /** @var ArgumentResolverRegistryInterface $resolvers */
        $resolvers = $container->get(ArgumentResolverRegistryInterface::class);
        $resolvers->add(new BoundModelArgumentResolver($provenance));
    }

    /**
     * The closure the binding middleware asks "who is calling".
     *
     * Two properties, and each one is a defect this used to ship.
     *
     * **It takes no argument.** The previous version read `_identity`, then
     * `identity`, then `_security_context` off the ServerRequest and took the
     * first authenticated thing it found. All three are PSR-7 attributes, so
     * anything in the pipeline — an application middleware, an extension, a
     * route middleware alias, anything prepended to the post-routing pipeline —
     * could name the caller the authorization hook was then asked about. A
     * closure with no request parameter cannot be defeated that way by
     * construction rather than by discipline, which is the only kind of
     * statement worth making here.
     *
     * **It goes through {@see AuthenticationState}, which memoises.** That
     * holder carries the request's {@see SecurityContext}, published by
     * {@see AuthenticationMiddleware}, and it is what BOTH auth shapes leave
     * behind — see the class docblock. Asking the
     * {@see \Pulsar\Auth\AuthManagerInterface} instead would be authoritative
     * too and would authenticate a second time on every `auth`-guarded bound
     * route, because the manager runs the guard chain on every call and the
     * context is the memo.
     *
     * Null when no auth stack is composed: there is then nothing that could
     * identify a caller, every request is unidentified, and the preset decides
     * what that means. {@see describeWiring()} declares it.
     *
     * @return (Closure(): ?IdentityInterface)|null
     */
    private static function identityResolver(ContainerInterface $container): ?Closure
    {
        if (!$container->has(AuthenticationState::class)) {
            return null;
        }

        /** @var AuthenticationState $state */
        $state = $container->get(AuthenticationState::class);

        return $state->authenticatedIdentity(...);
    }
}
