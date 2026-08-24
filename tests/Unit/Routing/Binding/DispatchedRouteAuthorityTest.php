<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\DispatchedRouteAwareInterface;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\PostRoutingPipeline;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingProvenance;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BoundModelArgumentResolver;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\RouteAccess;

use function ini_get;

/**
 * The route the binding layer decides from is the route the kernel dispatches.
 *
 * ## The gap these tests close
 *
 * The kernel writes the matched route to the `_route` request attribute and
 * then never reads it again: {@see Kernel::dispatchRoute()} closes over the
 * {@see MatchedRoute} it matched, invokes THAT route's handler, and resolves
 * that handler's arguments from THAT route's parameters. The binding middleware
 * used to read the attribute.
 *
 * Every frame between routing and the middleware can rewrite an attribute —
 * route-level middleware, anything piped into the {@see PostRoutingPipeline},
 * and, because that pipeline is container-bound and its `prepend()` is public,
 * anything an extension puts in FRONT of the binding middleware. So the two
 * could disagree, and a disagreement was not a crash: the kernel went on
 * serving the real handler while the middleware resolved, authorized and SEALED
 * models according to another route's declarations.
 *
 * The forgery only has to copy the real route's parameter names and values.
 * {@see BoundModelArgumentResolver} checks its attestation against the real
 * route's parameters, and a forged route carrying the same `{vault} => 9`
 * satisfies that check exactly. What the attacker chooses instead is everything
 * else the route says — here the `_without_authorization` opt-out on a route
 * declared {@see RouteAccess::Public}, which is a legal exemption ON THAT ROUTE
 * and turns the caller's own denied request into an authorized one.
 *
 * ## What replaced it
 *
 * Nothing compares two routes. The middleware has no route until the pipeline
 * gives it one: the kernel passes the dispatched {@see MatchedRoute} as an
 * argument to {@see MiddlewarePipeline::dispatch()}, which binds a per-dispatch
 * copy of every {@see DispatchedRouteAwareInterface} middleware to it before the
 * chain is built — before any frame that could rewrite an attribute exists. The
 * attribute is no longer an input to a binding decision, so there is no
 * mismatch to detect and no branch that could get the detection wrong.
 *
 * These tests make the two disagree on purpose, from the two places an
 * application can register a middleware after routing, and assert the request
 * is answered as the dispatched route says.
 */
#[CoversClass(ModelBindingMiddleware::class)]
#[CoversClass(MiddlewarePipeline::class)]
#[CoversClass(PostRoutingPipeline::class)]
#[CoversClass(Kernel::class)]
final class DispatchedRouteAuthorityTest extends TestCase
{
    /**
     * The route actually being served. A regulated preset, no opt-out, so every
     * model it binds must pass the authorization hook.
     */
    private const string REAL_PATH = '/vaults/{vault}';

    /**
     * The route the forgery names instead. Same parameter name, same model, and
     * a legal exemption from authorization — legal because the route declares
     * itself public, which is exactly the kind of route an application really
     * has next to a guarded one.
     */
    private const string DECOY_PATH = '/open-vaults/{vault}';

    // -----------------------------------------------------------------
    // Making the two disagree
    // -----------------------------------------------------------------

    #[Test]
    public function aPostRoutingMiddlewareCannotSubstituteTheRouteTheBindingLayerDecidesFrom(): void
    {
        $harness = $this->harness(hookAllows: false);

        // Prepended, so it runs in FRONT of the binding middleware inside the
        // pipeline the kernel dispatches. `prepend()` is public on the
        // container-bound PostRoutingPipeline, so this is a position any
        // extension can take without privilege.
        $harness->postRouting->prepend(new ForgingRouteMiddleware($this->decoyRoute()));

        $response = $harness->kernel->handle($this->request('/vaults/9'));

        $this->assertRefused($response, $harness);
    }

    #[Test]
    public function aRouteLevelMiddlewareCannotSubstituteItEither(): void
    {
        // The other place an application registers a frame after routing: route
        // middleware, which wraps the post-routing pipeline and therefore hands
        // it whatever request it likes.
        $harness = $this->harness(hookAllows: false, routeMiddlewareForges: true);

        $response = $harness->kernel->handle($this->request('/vaults/9'));

        $this->assertRefused($response, $harness);
    }

    /**
     * A refusal decided by the DISPATCHED route.
     *
     * The exact status is not this test's business: a hook denial answers `404`
     * rather than `403` on purpose, so that an authenticated caller cannot read
     * off which ids are real — see
     * {@see \Pulsar\Routing\Binding\ModelBindingException::authorizationFailed()}.
     * Pinning the number here would make this test fail the next time that
     * posture is argued, for a reason that has nothing to do with which route
     * was consulted. What must hold either way is that the caller was refused,
     * the handler never ran, and the policy was ASKED — a forged opt-out does
     * not make the hook say yes, it stops it being asked at all.
     */
    private function assertRefused(ResponseInterface $response, DispatchAuthorityHarness $harness): void
    {
        self::assertGreaterThanOrEqual(
            400,
            $response->getStatusCode(),
            'The dispatched route mandates authorization and the hook refused; a route '
            . 'substituted on the request must not turn that into a grant.',
        );
        self::assertNotSame('served', (string) $response->getBody());
        self::assertFalse(
            $harness->controller->ran,
            'The handler must not run for a caller the dispatched route refused.',
        );
        self::assertNull($harness->controller->received);
        self::assertSame(
            1,
            $harness->hook->calls,
            'The hook has to be CONSULTED for the dispatched route. Zero calls means the '
            . 'forged opt-out was honoured and the model was bound with no policy at all.',
        );
    }

    /**
     * The substituted route is a live exemption, not a straw man.
     *
     * Without this, the two tests above could pass because the decoy is inert —
     * a route that grants nothing proves nothing about a route that was never
     * consulted. Bound to the decoy DIRECTLY, the same middleware resolves the
     * model, never asks the hook, and hands it to the handler. That is precisely
     * what a rewritten `_route` used to buy, and precisely what the caller's own
     * request is refused.
     */
    #[Test]
    public function theSubstitutedRouteReallyWouldHaveAuthorizedNothing(): void
    {
        $port = new RecordingVaultResolver();
        $hook = new RecordingAuthorizationHook(false);
        $handler = new CapturingRequestHandler();

        $response = $this->middleware($port, $hook, new BindingProvenance())
            ->forDispatchedRoute($this->decoyRoute())
            ->process(new ServerRequest(method: 'GET', uri: '/vaults/9'), $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            0,
            $hook->calls,
            'The decoy declares the opt-out on a route declared public, so it legally '
            . 'authorizes nothing — which is the whole value of naming it.',
        );
        self::assertNotNull($handler->request);

        /** @var array<string, object> $bound */
        $bound = $handler->request->getAttribute('_bound_models');
        self::assertArrayHasKey('vault', $bound);
        self::assertInstanceOf(DispatchAuthorityVault::class, $bound['vault']);
    }

    /**
     * The mirror image, so the test above cannot pass by refusing everything.
     *
     * Same routes, same forgery, a hook that allows: the model is bound and
     * delivered — and it is the DISPATCHED route's binding, resolved for the
     * dispatched route's parameter, reaching the dispatched route's handler.
     */
    #[Test]
    public function theDispatchedRoutesOwnBindingStillReachesItsHandler(): void
    {
        $harness = $this->harness(hookAllows: true);
        $harness->postRouting->prepend(new ForgingRouteMiddleware($this->decoyRoute()));

        $response = $harness->kernel->handle($this->request('/vaults/9'));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($harness->controller->ran);
        self::assertNotNull($harness->controller->received);
        self::assertSame('9', $harness->controller->received->id);
        self::assertSame(1, $harness->hook->calls);
    }

    /**
     * The attribute is restored at the boundaries the kernel owns.
     *
     * The binding middleware does not read `_route` any more, but plenty of
     * other code does — controllers, argument resolvers, observability. A frame
     * that rewrote it was telling all of them that a route which is not being
     * served is being served, and the claim is false by construction. The kernel
     * puts its own value back before the handler frame.
     */
    #[Test]
    public function theHandlerIsHandedTheDispatchedRouteWhateverTheRequestWasToldEarlier(): void
    {
        $harness = $this->harness(hookAllows: true);
        $harness->postRouting->prepend(new ForgingRouteMiddleware($this->decoyRoute()));

        $harness->kernel->handle($this->request('/vaults/9'));

        self::assertNotNull($harness->controller->seenRoute);
        self::assertSame(self::REAL_PATH, $harness->controller->seenRoute->route->path);
    }

    // -----------------------------------------------------------------
    // Reaching the middleware directly, which visibility cannot prevent
    // -----------------------------------------------------------------

    /**
     * The forgery one call deeper: don't rewrite the attribute, call the
     * middleware.
     *
     * `forDispatchedRoute()` is public because
     * {@see DispatchedRouteAwareInterface} requires it, `process()` is public
     * because PSR-15 requires it, and the middleware is reachable — it is bound
     * in the container by {@see \Pulsar\Core\Wiring\ModelBindingWiring}, and
     * even unbound it is handed back by
     * {@see PostRoutingPipeline::snapshot()} on a pipeline that is itself
     * container-bound. Unregistering it therefore protects nothing, and the two
     * previous rounds that tried to hide it were each defeated one call deeper.
     *
     * What holds instead is on the record: {@see BindingProvenance} credits an
     * entry only for the MatchedRoute the kernel presents at the handler frame.
     * The mint below still happens, the model still lands in `_bound_models`,
     * and the argument resolver still declines it — so the handler receives
     * nothing rather than an object the hook was never asked about.
     */
    #[Test]
    public function aFrameThatMintsUnderItsOwnRouteReachesNoHandlerParameter(): void
    {
        $harness = $this->harness(hookAllows: true);

        // Piped AFTER the binding middleware, so the legitimate binding has
        // already happened and this frame is substituting for it.
        $harness->postRouting->pipe(new ContainerMintingMiddleware(
            $harness->kernel->container(),
            $this->decoyRoute(),
        ));

        // The handler declares the entity, so an unfilled parameter is a
        // TypeError the kernel reports through error_log(). Redirected, because
        // the diagnostic is the expected outcome here rather than noise.
        $previous = (string) ini_get('error_log');
        $sink = sys_get_temp_dir() . '/pulsar-dispatch-authority-' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $sink);

        try {
            $response = $harness->kernel->handle($this->request('/vaults/9'));
        } finally {
            ini_set('error_log', $previous);
            @unlink($sink);
        }

        self::assertSame(
            1,
            $harness->hook->calls,
            'sanity: the hook ran once, for the dispatched route. The decoy declares a legal '
            . 'opt-out, so the forged mint asked nothing.',
        );
        self::assertNull(
            $harness->controller->received,
            'A model minted under a route the kernel is not dispatching must not be sealed '
            . 'onto the handler, however faithfully it copies the parameter name and value.',
        );
        self::assertGreaterThanOrEqual(500, $response->getStatusCode());
    }

    /**
     * The mirror image: the same frame, minting under the route the kernel IS
     * dispatching, wins exactly what the request already grants.
     *
     * That is the residue this mechanism deliberately leaves. The route is no
     * longer the caller's to choose; the CALLER still is, because the identity
     * the binding layer authorizes against arrives on the request. Closing that
     * is a different change in a different place, and this test is here so the
     * boundary between the two is written down rather than assumed.
     */
    #[Test]
    public function mintingUnderTheKernelsOwnRouteGrantsOnlyWhatTheRouteAlreadyGrants(): void
    {
        $harness = $this->harness(hookAllows: true);

        $harness->postRouting->pipe(new ContainerMintingMiddleware(
            $harness->kernel->container(),
            null,
        ));

        $response = $harness->kernel->handle($this->request('/vaults/9'));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($harness->controller->received);
        self::assertSame('9', $harness->controller->received->id);
        self::assertSame(
            2,
            $harness->hook->calls,
            'The hook is asked again, under the dispatched route\'s own rules — a second '
            . 'answer to the question this request already asked, not a way past it.',
        );
    }

    // -----------------------------------------------------------------
    // The mechanism itself
    // -----------------------------------------------------------------

    #[Test]
    public function bindingToADispatchedRouteLeavesTheSharedInstanceUnbound(): void
    {
        // The container holds one middleware for the process lifetime and a
        // persistent worker runs concurrent requests through it. A route stored
        // on that object would be another request's route, so binding must
        // produce a copy — and the shared instance must stay inert.
        $port = new RecordingVaultResolver();
        $shared = $this->middleware($port, new RecordingAuthorizationHook(true), new BindingProvenance());

        $bound = $shared->forDispatchedRoute(new MatchedRoute($this->realRoute(), ['vault' => '9']));

        self::assertNotSame($shared, $bound);

        $handler = new CapturingRequestHandler();

        // The shared instance, handed a request that names the route in the very
        // attribute the middleware used to read, resolves nothing.
        $shared->process(
            new ServerRequest(method: 'GET', uri: '/vaults/9', attributes: [
                '_route' => new MatchedRoute($this->realRoute(), ['vault' => '9']),
            ]),
            $handler,
        );

        self::assertSame([], $port->calls);

        $bound->process(new ServerRequest(method: 'GET', uri: '/vaults/9'), $handler);

        self::assertCount(1, $port->calls, 'The bound copy is what carries the route.');
    }

    #[Test]
    public function thePipelineBindsEveryDispatchedRouteAwareMiddlewareToTheRouteItIsGiven(): void
    {
        $recorder = new DispatchedRouteRecordingMiddleware();
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($recorder);

        $matched = new MatchedRoute($this->realRoute(), ['vault' => '9']);

        $pipeline->dispatch(
            new ServerRequest(method: 'GET', uri: '/vaults/9'),
            static fn(): ResponseInterface => Response::text('ok'),
            $matched,
        );

        self::assertSame($matched, $recorder->seen);
    }

    #[Test]
    public function aPipelineGivenNoRouteBindsNothing(): void
    {
        // The global pipeline runs before routing, so there is no answer to give
        // and none is invented. A middleware that needs the dispatched route is
        // inert there rather than acting on a guess.
        $recorder = new DispatchedRouteRecordingMiddleware();
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe($recorder);

        $pipeline->dispatch(
            new ServerRequest(method: 'GET', uri: '/vaults/9'),
            static fn(): ResponseInterface => Response::text('ok'),
        );

        self::assertNull($recorder->seen);
        self::assertTrue($recorder->ran, 'Unbound is inert, not skipped: the frame still runs.');
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    /**
     * A kernel wired the way {@see \Pulsar\Core\Wiring\ModelBindingWiring}
     * wires one: the binder, the middleware and the argument resolver composed
     * from a single provenance record, the middleware piped into the
     * post-routing pipeline, the resolver on the kernel's argument chain.
     */
    private function harness(bool $hookAllows, bool $routeMiddlewareForges = false): DispatchAuthorityHarness
    {
        $kernel = new Kernel();
        $controller = new GuardedVaultController();
        $kernel->container()->instance(GuardedVaultController::class, $controller);
        $kernel->container()->instance(PublicVaultController::class, new PublicVaultController());

        $port = new RecordingVaultResolver();
        $hook = new RecordingAuthorizationHook($hookAllows);
        $provenance = new BindingProvenance();

        $middleware = $this->middleware($port, $hook, $provenance, $kernel->container());

        // Registered exactly as ModelBindingWiring::compose() registers it, so
        // the tests above reach it the way an extension would.
        $kernel->container()->instance(ModelBindingMiddleware::class, $middleware);

        /** @var PostRoutingPipeline $postRouting */
        $postRouting = $kernel->container()->get(PostRoutingPipeline::class);
        $postRouting->pipe($middleware);

        /** @var ArgumentResolverRegistryInterface $resolvers */
        $resolvers = $kernel->container()->get(ArgumentResolverRegistryInterface::class);
        $resolvers->add(new BoundModelArgumentResolver($provenance));

        $routeMiddleware = [];
        if ($routeMiddlewareForges) {
            $kernel->container()->instance(
                ForgingRouteMiddleware::class,
                new ForgingRouteMiddleware($this->decoyRoute()),
            );
            $routeMiddleware = [ForgingRouteMiddleware::class];
        }

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: self::REAL_PATH,
            handler: [GuardedVaultController::class, 'show'],
            name: 'vaults.show',
            middleware: $routeMiddleware,
        ));

        // Registered as a real route, because it is one: the decoy is an
        // ordinary public endpoint sitting next to a guarded one, which is what
        // makes its opt-out legal and the forgery worth attempting.
        $kernel->router()->add($this->decoyRouteDefinition());

        return new DispatchAuthorityHarness($kernel, $postRouting, $controller, $hook, $port);
    }

    private function middleware(
        RecordingVaultResolver $port,
        RecordingAuthorizationHook $hook,
        BindingProvenance $provenance,
        ?ContainerInterface $container = null,
    ): ModelBindingMiddleware {
        $kernelContainer = $container ?? new Kernel()->container();

        return new ModelBindingMiddleware(
            new ModelBinder($port, new BindingResolver(), $kernelContainer, $provenance),
            new ModelBindingConfig(preset: BindingPreset::Banking),
            $hook,
            null,
            static fn(): IdentityInterface => new Identity('user-9', 'Nine'),
        );
    }

    private function realRoute(): Route
    {
        return new Route(
            methods: [Method::GET],
            path: self::REAL_PATH,
            handler: [GuardedVaultController::class, 'show'],
            name: 'vaults.show',
        );
    }

    private function decoyRouteDefinition(): Route
    {
        return new Route(
            methods: [Method::GET],
            path: self::DECOY_PATH,
            handler: [PublicVaultController::class, 'show'],
            name: 'vaults.open',
            attributes: [
                '_without_authorization' => true,
                RouteAccess::ATTRIBUTE => RouteAccess::Public,
                RouteAccess::REASON_ATTRIBUTE => 'Published vault summaries carry no personal data.',
            ],
        );
    }

    /**
     * The forged match: the decoy route, carrying the REAL route's parameter
     * name and value so every value-level check downstream still passes.
     */
    private function decoyRoute(): MatchedRoute
    {
        return new MatchedRoute($this->decoyRouteDefinition(), ['vault' => '9']);
    }

    private function request(string $path): ServerRequestInterface
    {
        return new ServerRequest(method: 'GET', uri: 'http://localhost' . $path);
    }
}

/**
 * What one dispatch is assembled from, kept together so each test asserts on the
 * objects the kernel actually ran.
 */
final readonly class DispatchAuthorityHarness
{
    public function __construct(
        public Kernel $kernel,
        public PostRoutingPipeline $postRouting,
        public GuardedVaultController $controller,
        public RecordingAuthorizationHook $hook,
        public RecordingVaultResolver $port,
    ) {}
}

/** The model both routes name. */
final readonly class DispatchAuthorityVault
{
    public function __construct(public string $id) {}
}

/**
 * The handler of the route being dispatched. Records whether it ran, what it was
 * handed, and which route the request said was being served when it did.
 */
final class GuardedVaultController
{
    public bool $ran = false;
    public ?DispatchAuthorityVault $received = null;
    public ?MatchedRoute $seenRoute = null;

    public function show(ServerRequestInterface $request, DispatchAuthorityVault $vault): ResponseInterface
    {
        $this->ran = true;
        $this->received = $vault;

        /** @var MatchedRoute|null $route */
        $route = $request->getAttribute('_route');
        $this->seenRoute = $route;

        return Response::text('served');
    }
}

/** The decoy's handler. Never invoked by these tests; it exists so the decoy is a real route. */
final class PublicVaultController
{
    public function show(DispatchAuthorityVault $vault): ResponseInterface
    {
        return Response::text('open');
    }
}

/**
 * Hands back a model for anything asked of it, and records what was asked.
 */
final class RecordingVaultResolver implements ModelResolverPort
{
    /** @var list<array{class: string, key: string, value: string|int}> */
    public array $calls = [];

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): object
    {
        $this->calls[] = ['class' => $modelClass, 'key' => $keyName, 'value' => $keyValue];

        return new DispatchAuthorityVault((string) $keyValue);
    }

    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): object {
        $this->calls[] = ['class' => $modelClass, 'key' => $keyName, 'value' => $keyValue];

        return new DispatchAuthorityVault((string) $keyValue);
    }
}

/**
 * The policy. Counting the calls is half the assertion: a forged opt-out does
 * not make the hook say yes, it stops it being asked at all.
 */
final class RecordingAuthorizationHook implements AuthorizationHookInterface
{
    public int $calls = 0;

    public function __construct(private readonly bool $allow) {}

    #[Override]
    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        $this->calls++;

        return $this->allow;
    }
}

/**
 * The attack, as an ordinary application middleware: overwrite `_route` with
 * another route and hand the request on.
 */
final readonly class ForgingRouteMiddleware implements MiddlewareInterface
{
    public function __construct(private MatchedRoute $forged) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('_route', $this->forged));
    }
}

/**
 * Records the route the pipeline bound it to, and whether an unbound copy still
 * runs.
 */
final class DispatchedRouteRecordingMiddleware implements DispatchedRouteAwareInterface
{
    public ?MatchedRoute $seen = null;
    public bool $ran = false;

    /** The instance the pipeline bound, so a test can read what the copy saw. */
    private ?self $origin = null;

    #[Override]
    public function forDispatchedRoute(MatchedRoute $route): self
    {
        $bound = clone $this;
        $bound->seen = $route;
        $bound->origin = $this;

        return $bound;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $target = $this->origin ?? $this;
        $target->seen = $this->seen;
        $target->ran = true;

        return $handler->handle($request);
    }
}

/** Terminates a middleware chain in the unit-level tests. */
final class CapturingRequestHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $request = null;

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return Response::text('handled');
    }
}

/**
 * The attack this round closes: resolve the binding middleware from the
 * container, bind it to a route of one's own, and mint.
 *
 * A `null` route means "use the one the kernel is dispatching", read off
 * `_route` — the control case, which proves the refusal above is about the
 * ROUTE and not about the call.
 */
final class ContainerMintingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ?MatchedRoute $forged,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var ModelBindingMiddleware $middleware */
        $middleware = $this->container->get(ModelBindingMiddleware::class);

        /** @var MatchedRoute|null $dispatched */
        $dispatched = $request->getAttribute('_route');
        $route = $this->forged ?? $dispatched;

        if ($route === null) {
            return $handler->handle($request);
        }

        $capture = new CapturingRequestHandler();
        $middleware->forDispatchedRoute($route)->process(
            new ServerRequest(method: 'GET', uri: 'http://localhost/open-vaults/9'),
            $capture,
        );

        /** @var array<string, object> $minted */
        $minted = $capture->request?->getAttribute('_bound_models') ?? [];

        return $handler->handle($request->withAttribute('_bound_models', $minted));
    }
}
