<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\AuthenticationState;
use Pulsar\Auth\AuthManagerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Guard\GuardInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Middleware\AuthenticationMiddleware;
use Pulsar\Auth\Middleware\AuthorizationMiddleware;
use Pulsar\Auth\SecurityContext;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\Exception\ConfigException;
use Pulsar\Container\Container;
use Pulsar\Core\Boot\DeferredComposition;
use Pulsar\Core\Controller\ArgumentResolverChain;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Controller\HandlerParameter;
use Pulsar\Core\Controller\HandlerSignature;
use Pulsar\Core\Wiring\ConfigLoaderRegistrar;
use Pulsar\Core\Wiring\Contract\OptionalBinding;
use Pulsar\Core\Wiring\ModelBindingWiring;
use Pulsar\Core\Wiring\SagaWiring;
use Pulsar\Core\Wiring\ServiceWiringInterface;
use Pulsar\Core\Wiring\TenancyWiring;
use Pulsar\Core\Wiring\WiringList;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\MiddlewareInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\Middleware\PostRoutingPipeline;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BoundModelArgumentResolver;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\PolicyAuthorizationHook;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use ReflectionFunction;
use ReflectionProperty;

use function array_filter;
use function array_map;
use function array_search;
use function array_values;
use function bin2hex;
use function dirname;
use function file_put_contents;
use function get_debug_type;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * Route model binding shipped documented, #[Api]-marked and completely inert:
 * nothing in the composition root ever built the binder, the middleware or the
 * authorization hook, so 260 lines of documentation described a feature that
 * could not run. These tests hold the composition itself, not the classes it
 * composes.
 *
 * Four properties matter and each is asserted on what the boot PRODUCED — the
 * pipelines, the container, and the config file the framework ships — never on
 * the shape of the wiring source:
 *
 *  1. With no ModelResolverPort bound, the boot is unchanged. Not "mostly
 *     unchanged": no middleware in either pipeline, no resolver on the kernel's
 *     argument chain, nothing new in the container beyond the config DTO.
 *  2. With one bound, the whole chain assembles — and assembles late enough to
 *     see an extension's port and a route file's explicit bindings, neither of
 *     which exists while wire() is running.
 *  3. The middleware lands where a matched route is already on the request, and
 *     where the caller is knowable. Piped into the global pipeline it would be
 *     constructed, registered, dispatched — and would return on its first line
 *     every time, which is indistinguishable from never wiring it at all.
 *  4. It works out WHO the caller is under both auth arrangements, not only the
 *     one that happens to leave an identity lying on the request. "Knowable" and
 *     "known" are different facts, and the difference used to be an authorization
 *     bypass — see the authentication-shape tests below.
 */
#[CoversClass(ModelBindingWiring::class)]
final class ModelBindingWiringTest extends TestCase
{
    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    #[Test]
    public function publishesTheConfigSectionRatherThanAHardCodedDefault(): void
    {
        $harness = $this->wire(
            configBody: "'preset' => 'healthcare', 'allowed_key_names' => ['id', 'public_ref']",
        );

        self::assertTrue($harness->container->has(ModelBindingConfig::class));

        $config = $harness->container->get(ModelBindingConfig::class);
        self::assertInstanceOf(ModelBindingConfig::class, $config);
        self::assertSame(BindingPreset::Healthcare, $config->preset);
        self::assertTrue($config->isRegulatedPreset());
        self::assertSame(['id', 'public_ref'], $config->allowedKeyNames);
    }

    #[Test]
    public function fallsBackToTheDtoDefaultsWhenNoConfigFileShips(): void
    {
        // The allow-list is the set of columns a URL may look a record up by, and
        // it has exactly one enforcement point: the resolver, before it builds
        // SQL. A wiring that invented its own default here would put the shipped
        // list in two places — this seam and the DTO — that could disagree, and
        // the resolver would enforce whichever it was handed.
        $harness = $this->wire(configBody: null);

        $config = $harness->container->get(ModelBindingConfig::class);
        self::assertInstanceOf(ModelBindingConfig::class, $config);
        self::assertEquals(new ModelBindingConfig(), $config);
        self::assertSame(['id', 'uuid', 'slug'], $config->allowedKeyNames);
    }

    #[Test]
    public function theNoConfigFilePathIsTheEnforcingPostureAndNotTheOptInOne(): void
    {
        // The same seam as above, asserted on the half that decides whether an
        // unidentified caller is handed the model. `new ModelBindingConfig()`
        // used to construct the permissive preset while config/model_binding.php
        // shipped a regulated one, so an application that never published the
        // section ran the opposite policy from the one its own config file
        // documented — and got there by editing nothing.
        $harness = $this->wire(configBody: null);

        $config = $harness->container->get(ModelBindingConfig::class);
        self::assertInstanceOf(ModelBindingConfig::class, $config);
        self::assertTrue(
            $config->isRegulatedPreset(),
            'an application with no config/model_binding.php must still mandate authorization on bound models',
        );
        self::assertNotSame(BindingPreset::Standard, $config->preset);
    }

    #[Test]
    public function theShippedConfigFileMandatesAuthorizationRatherThanOfferingIt(): void
    {
        // Read from the file the framework actually ships, not from a fixture:
        // the value is a security posture, and the only copy that protects an
        // application is the one in config/model_binding.php.
        //
        // Under the permissive preset a request with no authenticated caller
        // binds the model and hands it to the controller with no policy check —
        // one frame from an AuthorizationMiddleware that refuses a route merely
        // for declaring no permission. Changing this back is a decision somebody
        // has to make in the open, and this test is where they will have to make
        // it.
        /** @var mixed $shipped */
        $shipped = require dirname(__DIR__, 4) . '/config/model_binding.php';

        self::assertIsArray($shipped);
        self::assertArrayHasKey('preset', $shipped);

        // Read through the same conversion the boot uses, so a value this test
        // would accept but a real boot would refuse cannot exist.
        self::assertTrue(
            ModelBindingConfig::fromArray(['preset' => $shipped['preset']])->isRegulatedPreset(),
            'config/model_binding.php must ship a preset that makes authorization mandatory on every bound model.',
        );
    }

    #[Test]
    public function aMisspelledPresetAbortsConfigLoadInsteadOfSelectingThePermissivePath(): void
    {
        // The earliest moment there is: ConfigManager::load(), before wire() has
        // run and long before a request. 'Banking' used to load cleanly and
        // leave the application on the opt-in path, because the check was a
        // case-sensitive comparison against three literals and an unrecognized
        // preset was indistinguishable from a deliberately permissive one.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/Banking/');

        $this->wire(configBody: "'preset' => 'Banking'");
    }

    #[Test]
    public function theRefusalReachesTheOperatorThroughTheRealConfigLoader(): void
    {
        // Same path, a different misspelling, asserted on what the message has
        // to carry: the file to open, and the values that would have worked.
        try {
            $this->wire(configBody: "'preset' => 'bankng'");
            self::fail('A preset the framework does not recognize must abort the boot.');
        } catch (ConfigException $e) {
            self::assertStringContainsString('model_binding.php', $e->getMessage());
            self::assertStringContainsString('bankng', $e->getMessage());
            self::assertStringContainsString('banking', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Inert without a resolver port
    // -----------------------------------------------------------------

    #[Test]
    public function composesNothingAtAllWithoutAModelResolverPort(): void
    {
        $harness = $this->wire();
        $harness->finishBoot();

        self::assertTrue(
            $harness->postRouting->isEmpty(),
            'An application with no persistence adapter must not gain a post-routing middleware frame.',
        );
        self::assertSame(
            [],
            $harness->resolvers->resolvers,
            'An application with no persistence adapter must not gain an argument resolver.',
        );
        self::assertSame([], $harness->middleware->snapshot());
        self::assertFalse($harness->container->has(ModelBindingMiddleware::class));
    }

    #[Test]
    public function theAbsentPortCostsExactlyOneContainerLookupAndNoRequestWork(): void
    {
        // Silence has to be free, or every application without an ORM pays for a
        // feature it does not use on every request. The observable form of "free"
        // is that the kernel's two fast paths stay available: an empty
        // post-routing pipeline (dispatch calls the handler directly) and an
        // empty resolver chain (argument building skips the chain entirely).
        $harness = $this->wire();
        $harness->finishBoot();

        self::assertTrue($harness->postRouting->isEmpty());
        self::assertSame([], $harness->resolvers->resolvers);

        $response = $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7'),
            static fn(): ResponseInterface => Response::text('handler'),
        );

        self::assertSame('handler', (string) $response->getBody());
    }

    // -----------------------------------------------------------------
    // Full assembly
    // -----------------------------------------------------------------

    #[Test]
    public function assemblesTheWholeChainWhenAPortIsBound(): void
    {
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        // The middleware is registered and the binder is NOT, which is a
        // security property rather than an omission. `bindWithMeta()` is public
        // and mints the provenance the argument resolver seals on, so a binder
        // in the container is a mint anything holding the container can reach:
        // load a row through the application's own resolver port, mint an
        // attestation for it, and the seal is forged a step earlier than the
        // `_bound_models` attribute it exists to distrust. Resolving the
        // middleware instead gets `process()`, which decides everything before a
        // row is read.
        self::assertFalse(
            $harness->container->has(ModelBinder::class),
            'the minting operation must not be reachable as a service',
        );
        self::assertTrue($harness->container->has(ModelBindingMiddleware::class));
        self::assertTrue($harness->container->has(AuthorizationHookInterface::class));

        $piped = array_filter(
            $harness->postRouting->snapshot(),
            static fn(mixed $entry): bool => $entry instanceof ModelBindingMiddleware,
        );
        self::assertCount(1, $piped, 'The binding middleware must be piped exactly once.');

        $argumentResolvers = array_filter(
            $harness->resolvers->resolvers,
            static fn(object $entry): bool => $entry instanceof BoundModelArgumentResolver,
        );
        self::assertCount(
            1,
            $argumentResolvers,
            'Resolving a model without delivering it to the handler leaves every bound route a TypeError.',
        );
    }

    /**
     * The two halves of delivery have to share ONE provenance record.
     *
     * `BoundModelArgumentResolver` seals a model only when the record it holds
     * says the binder resolved that instance for that parameter and that URL
     * value. Composing the binder with one record and the resolver with another
     * — two `new BindingProvenance()` calls instead of one variable — leaves a
     * boot that looks completely assembled and delivers nothing: every bound
     * route would fall back to the raw route string, and a controller typed on
     * the entity would 500. Nothing about the shape of the wiring shows that, so
     * it is asserted on what a dispatch produces.
     *
     * The handler is the kernel's own step, run where the kernel runs it: ask
     * the composed chain what it can fill, from the request the middleware
     * leaves behind.
     */
    #[Test]
    public function theComposedResolverDeliversTheModelTheComposedBinderResolved(): void
    {
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        $matched = $this->matchedRoute('/widgets/{widget}', ['widget' => '7']);
        $harness->establishCaller(new Identity('user-9', 'Nine'));

        /** @var array<string, mixed> $delivered */
        $delivered = [];

        $response = $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7', attributes: ['_route' => $matched]),
            function (ServerRequestInterface $bound) use ($harness, $matched, &$delivered): ResponseInterface {
                $delivered = $harness->resolvers->resolveArguments(
                    new HandlerSignature(TypedWidgetController::class, 'show', [
                        new HandlerParameter('widget', WiredWidget::class, false, false, null),
                    ]),
                    $bound,
                    $matched->parameters,
                );

                return Response::text('handler');
            },
            $matched,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey(
            'widget',
            $delivered,
            'The composed resolver must seal the model the composed binder resolved; a second provenance record delivers nothing.',
        );
        self::assertInstanceOf(WiredWidget::class, $delivered['widget']);
    }

    /**
     * THE REPLAY, through the composed chain.
     *
     * An attestation used to be good for the life of the OBJECT, and objects
     * outlive requests: a persistence layer with an identity map hands the same
     * instance to every request that asks for that row. So a widget one caller's
     * request resolved and AUTHORIZED could be dropped into `_bound_models` on a
     * later request by any frame inner to the binding middleware, and the
     * composed resolver would seal it onto the handler — undisplaceable, on the
     * parameter whose entity type hint is what makes the route read as
     * authorized, on the strength of a decision made about somebody else.
     *
     * The second request is deliberately one that binds NOTHING. That is where
     * the replay had to be answered: the binder never runs on such a route, so
     * nothing re-decides the attribute, and most routes in most applications are
     * this shape. The middleware opens the request's binding pass before it
     * looks at what the route binds, which is what ends the earlier attestation
     * here too.
     */
    #[Test]
    public function aModelAnEarlierRequestResolvedIsNotSealedOntoALaterOne(): void
    {
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        $bound = $this->matchedRoute('/widgets/{widget}', ['widget' => '7']);
        $harness->establishCaller(new Identity('user-9', 'Nine'));
        $earlier = null;

        (void) $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7', attributes: ['_route' => $bound]),
            function (ServerRequestInterface $request) use (&$earlier): ResponseInterface {
                /** @var array<string, object> $models */
                $models = $request->getAttribute('_bound_models');
                $earlier = $models['widget'];

                return Response::text('handler');
            },
            $bound,
        );

        self::assertInstanceOf(WiredWidget::class, $earlier, 'the first request must actually have bound one');

        // A different request, on a route whose handler types the parameter as a
        // string — nothing is bound, and the object below is the previous
        // caller's.
        $unbound = $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], UntypedWidgetController::class);

        /** @var array<string, mixed> $delivered */
        $delivered = [];

        (void) $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7', attributes: [
                '_route' => $unbound,
                '_identity' => new Identity('user-9', 'Nine'),
            ]),
            function (ServerRequestInterface $request) use ($harness, $earlier, &$delivered): ResponseInterface {
                $delivered = $harness->resolvers->resolveArguments(
                    new HandlerSignature(TypedWidgetController::class, 'show', [
                        new HandlerParameter('widget', WiredWidget::class, false, false, null),
                    ]),
                    $request->withAttribute('_bound_models', ['widget' => $earlier]),
                    ['widget' => '7'],
                );

                return Response::text('handler');
            },
            $unbound,
        );

        self::assertSame(
            [],
            $delivered,
            'an attestation must not survive into a request whose authorization never produced it',
        );
    }

    #[Test]
    public function theGateIsOnlyResolvedIfSomethingBindsAPort(): void
    {
        // bind(), not instance(): an application that installs no ORM never
        // builds the hook, and therefore never touches the Gate at boot.
        $harness = $this->wire();
        $harness->finishBoot();

        self::assertSame(
            0,
            $harness->gate->resolutions,
            'The Gate-backed hook must stay unbuilt until a binding pipeline actually needs it.',
        );

        $withPort = $this->wire();
        $withPort->bindResolverPort(new RecordingModelResolver());
        $withPort->finishBoot();

        self::assertSame(1, $withPort->gate->resolutions);
    }

    // -----------------------------------------------------------------
    // Position in the pipelines
    // -----------------------------------------------------------------

    #[Test]
    public function theMiddlewareLandsInThePostRoutingPipelineAndNeverInTheGlobalOne(): void
    {
        // The global pipeline runs BEFORE routing: its innermost handler is the
        // kernel's dispatch, and `_route` is attached inside that dispatch. A
        // ModelBindingMiddleware piped there sees a null route on its first line
        // and returns — wired, dispatched, and permanently inert.
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        $global = array_filter(
            $harness->middleware->snapshot(),
            static fn(mixed $entry): bool => $entry instanceof ModelBindingMiddleware,
        );
        self::assertSame([], $global, 'The binding middleware must not be piped into the pre-routing pipeline.');

        $postRouting = array_filter(
            $harness->postRouting->snapshot(),
            static fn(mixed $entry): bool => $entry instanceof ModelBindingMiddleware,
        );
        self::assertCount(1, $postRouting);
    }

    #[Test]
    public function theMiddlewareIsInertOnARequestThatHasNotBeenRoutedYet(): void
    {
        // Direct evidence for the assertion above: the same middleware, handed a
        // request without `_route`, resolves nothing. That is exactly what the
        // global pipeline would hand it.
        $harness = $this->wire();
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        (void) $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7'),
            static fn(): ResponseInterface => Response::text('handler'),
        );

        self::assertSame([], $port->resolveCalls);
    }

    // -----------------------------------------------------------------
    // The two authentication shapes
    // -----------------------------------------------------------------
    //
    // The identity the binding layer authorizes against is produced by one of
    // two arrangements, and they leave DIFFERENT things on the request. Both are
    // exercised here with the real middleware AuthWiring builds, never a
    // stand-in that attaches what the assertion wants to find:
    //
    //  A. The globally piped `AuthenticationMiddleware`. It does not
    //     authenticate. It attaches a lazy `_security_context` and sets
    //     `_identity` to an AnonymousIdentity; the guards are consulted only
    //     when something calls `SecurityContext::identity()`.
    //  B. The route-level `auth` alias, `AuthorizationMiddleware`. It calls
    //     `SecurityContext::identity()` and writes the resolved identity back
    //     onto `_identity`.
    //
    // A binding layer that reads `_identity` alone is therefore correct under B
    // and blind under A — where every caller looks anonymous no matter who they
    // signed in as.

    #[Test]
    public function resolvesTheCallerUnderTheGloballyPipedAuthenticationMiddleware(): void
    {
        // Shape A, and the whole point of it: the caller IS authenticated — the
        // guard behind the AuthManager returns a real identity — but nothing has
        // asked for it yet when the binding middleware runs. `_identity` still
        // holds the anonymous placeholder. The models must nonetheless be
        // resolved for, and authorized against, user-9.
        $harness = $this->wire();
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $authManager = new StubAuthManager(new Identity('user-9', 'Nine'));
        $harness->middleware->pipe(new AuthenticationMiddleware($authManager, $harness->authState));

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7']),
        );

        self::assertInstanceOf(ServerRequestInterface::class, $seen->request);

        $bound = $seen->request->getAttribute('_bound_models');
        self::assertIsArray($bound);
        self::assertArrayHasKey('widget', $bound);
        self::assertInstanceOf(WiredWidget::class, $bound['widget']);

        self::assertCount(1, $port->resolveCalls);
        self::assertSame(
            'user-9',
            $port->resolveCalls[0]['context']->subjectId,
            'Under the global shape the identity is resolvable but unresolved; the binding layer must resolve it.',
        );
        self::assertSame(
            'user-9',
            $harness->gate->lastIdentityId,
            'Every resolved model must pass the authorization hook, as the caller and not as an anonymous stand-in.',
        );
    }

    #[Test]
    public function resolvesTheCallerUnderTheRouteLevelAuthAlias(): void
    {
        // Shape B: AuthorizationMiddleware has already resolved the identity and
        // written it onto `_identity` before the post-routing pipeline runs, so
        // the binding layer reads it straight off the request.
        $harness = $this->wire();
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $authManager = new StubAuthManager(new Identity('user-9', 'Nine'));
        $harness->middleware->pipe(new AuthenticationMiddleware($authManager, $harness->authState));

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            // `_authenticated` rather than a named permission: AuthorizationMiddleware
            // default-denies a route declaring none, and a named one would make the
            // Gate record ITS identity, hiding whether the binding hook ran at all.
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], attributes: ['permissions' => ['_authenticated']]),
            routeMiddleware: new AuthorizationMiddleware($harness->gate),
        );

        self::assertInstanceOf(ServerRequestInterface::class, $seen->request);
        self::assertSame(200, $seen->response->getStatusCode());
        self::assertCount(1, $port->resolveCalls);
        self::assertSame('user-9', $port->resolveCalls[0]['context']->subjectId);
        self::assertSame('user-9', $harness->gate->lastIdentityId);
    }

    #[Test]
    public function authenticatesOnceUnderTheAuthAliasRatherThanASecondTime(): void
    {
        // The fallback must go through the request's own SecurityContext, which
        // memoises. Reaching past it to the AuthManager would authenticate twice
        // per request on every `auth`-guarded bound route — a second session read
        // or a second token verification for an answer already on the request.
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        $authManager = new StubAuthManager(new Identity('user-9', 'Nine'));
        $harness->middleware->pipe(new AuthenticationMiddleware($authManager, $harness->authState));

        $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], attributes: ['permissions' => ['_authenticated']]),
            routeMiddleware: new AuthorizationMiddleware($harness->gate),
        );

        self::assertSame(1, $authManager->authenticateCalls);
    }

    #[Test]
    public function leavesAnAnonymousCallerAnonymousUnderTheGlobalShape(): void
    {
        // The other half of the fix, and the half a careless one breaks: resolving
        // through the SecurityContext must not turn the AnonymousIdentity the
        // guards hand back into "an identity is present". Under a regulated preset
        // that would be an authorization bypass wearing a 200.
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $authManager = new StubAuthManager(new AnonymousIdentity());
        $harness->middleware->pipe(new AuthenticationMiddleware($authManager, $harness->authState));

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7']),
        );

        self::assertSame(401, $seen->response->getStatusCode());
        self::assertNull($seen->request, 'The handler must not run for an unauthenticated caller under a regulated preset.');
        self::assertNull($harness->gate->lastIdentityId, 'No identity means no policy question to ask.');
        self::assertSame(
            [],
            $port->resolveCalls,
            'An anonymous caller must not reach the resolver at all — not even with a null subject. '
            . 'Authentication does not need the model, so the 401 is decided before any query runs; '
            . 'reading one first would let response timing and error shape tell an anonymous caller '
            . 'whether the id exists.',
        );
    }

    // -----------------------------------------------------------------
    // The caller is not a request attribute
    // -----------------------------------------------------------------
    //
    // The binding layer used to take the first authenticated IdentityInterface
    // it found on `_identity`, then `identity`, then `_security_context`. All
    // three are PSR-7 attributes, so authorization on every bound route was
    // decided by whichever frame in the pipeline wrote last — an application
    // middleware, an extension, a route middleware alias, anything prepended to
    // the post-routing pipeline. Both tests below spoof ALL THREE from an
    // ordinary application middleware, in the position an application would
    // pipe one.

    #[Test]
    public function anIdentityAttributeFromApplicationMiddlewareDoesNotAuthenticateACaller(): void
    {
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        // Nobody is signed in: the guards behind the real AuthenticationMiddleware
        // answer anonymous.
        $harness->middleware->pipe(
            new AuthenticationMiddleware(new StubAuthManager(new AnonymousIdentity()), $harness->authState),
        );
        $harness->middleware->pipe(new IdentityAttributeSpoofingMiddleware(new Identity('attacker', 'Attacker')));

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7']),
        );

        self::assertSame(401, $seen->response->getStatusCode());
        self::assertNull($seen->request, 'A spoofed attribute must not carry a caller past the preset.');
        self::assertNull($harness->gate->lastIdentityId, 'No identity means no policy question to ask.');
        self::assertSame([], $port->resolveCalls, 'Nothing may be read on behalf of a caller nobody authenticated.');
    }

    #[Test]
    public function theHookIsAskedAboutTheEstablishedCallerAndNotTheAttribute(): void
    {
        // The other half, and the one a partial fix leaves open: a REAL caller
        // is signed in, and a later frame renames them. The model must be
        // resolved for, and authorized against, the identity the guards
        // produced.
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $harness->middleware->pipe(
            new AuthenticationMiddleware(new StubAuthManager(new Identity('user-9', 'Nine')), $harness->authState),
        );
        $harness->middleware->pipe(new IdentityAttributeSpoofingMiddleware(new Identity('attacker', 'Attacker')));

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7']),
        );

        self::assertSame(200, $seen->response->getStatusCode());
        self::assertCount(1, $port->resolveCalls);
        self::assertSame('user-9', $port->resolveCalls[0]['context']->subjectId);
        self::assertSame('user-9', $harness->gate->lastIdentityId);
    }

    #[Test]
    public function theIdentityResolverTakesNoRequestAtAll(): void
    {
        // The property above, asserted on the signature rather than on one
        // arrangement of frames: a closure with no parameters cannot be handed a
        // request, so no attribute can reach it whatever the pipeline looks
        // like. This is the statement the two tests above sample.
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        $middleware = $harness->container->get(ModelBindingMiddleware::class);
        $resolver = new ReflectionProperty(ModelBindingMiddleware::class, 'identityResolver')->getValue($middleware);

        self::assertInstanceOf(Closure::class, $resolver);
        self::assertSame(
            0,
            new ReflectionFunction($resolver)->getNumberOfParameters(),
            'The binding layer must not be able to read the caller off anything the pipeline can write.',
        );
    }

    #[Test]
    public function composesWithNoCallerAtAllWhenNoAuthStackIsWired(): void
    {
        // No AuthWiring, so no AuthenticationState, so no resolver. Under the
        // regulated preset that refuses every bound route — correct, and silent,
        // which is why describeWiring() declares it.
        $harness = $this->wire(configBody: "'preset' => 'banking'", bindAuthState: false);
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7']),
        );

        self::assertSame(401, $seen->response->getStatusCode());
        self::assertSame([], $port->resolveCalls);

        $gated = array_map(
            static fn(OptionalBinding $o): string => $o->binding,
            new ModelBindingWiring()->describeWiring()->optional,
        );
        self::assertContains(AuthenticationState::class, $gated);
    }

    // -----------------------------------------------------------------
    // A type hint cannot switch off authorization
    // -----------------------------------------------------------------
    //
    // `ReflectionNamedType::isBuiltin()` answers true for `object`, `mixed`,
    // `iterable` and `callable`, and for every member of `object|string`. The
    // binding resolver read that as "this parameter is not a model", so the
    // route bound nothing, the plan came back empty, and the middleware handed
    // the request on — skipping the regulated preset's identity requirement and
    // the authorization hook with it. Measured before the fix: an anonymous
    // caller got 200 from each of those hints and 401 from a named class, on the
    // same route and the same handler class.

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function hintsThatAdmitAnObjectWithoutNamingOne(): iterable
    {
        yield 'object' => [ObjectHintedWidgetController::class];
        yield 'mixed' => [MixedHintedWidgetController::class];
        yield 'object|string' => [UnionObjectHintedWidgetController::class];
        yield 'iterable' => [IterableHintedWidgetController::class];
        yield '?object' => [NullableObjectHintedWidgetController::class];
        yield 'callable' => [CallableHintedWidgetController::class];
    }

    /**
     * @param class-string $controller
     */
    #[Test]
    #[DataProvider('hintsThatAdmitAnObjectWithoutNamingOne')]
    public function aHintThatAdmitsAnObjectWithoutNamingOneStillRefusesAnAnonymousCaller(string $controller): void
    {
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $harness->middleware->pipe(
            new AuthenticationMiddleware(new StubAuthManager(new AnonymousIdentity()), $harness->authState),
        );

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], $controller),
        );

        self::assertSame(401, $seen->response->getStatusCode());
        self::assertNull($seen->request, 'The handler must not run: the hint says a model belongs in that slot.');
        self::assertSame([], $port->resolveCalls);
    }

    /**
     * @param class-string $controller
     */
    #[Test]
    #[DataProvider('hintsThatAdmitAnObjectWithoutNamingOne')]
    public function aHintThatAdmitsAnObjectWithoutNamingOneRefusesTheRouteForEveryone(string $controller): void
    {
        // Fail closed means refusing the ROUTE, not the request: the refusal is
        // read off the handler signature and the route path, so it is the same
        // answer for every caller and every id, and it cannot be used to tell
        // one id from another. An identified caller gets the 500 that says the
        // route is misdeclared; the anonymous caller above gets the same 401 a
        // correctly hinted route gives them, which is what keeps the two
        // indistinguishable.
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $harness->middleware->pipe(
            new AuthenticationMiddleware(new StubAuthManager(new Identity('user-9', 'Nine')), $harness->authState),
        );

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], $controller),
        );

        self::assertSame(500, $seen->response->getStatusCode());
        self::assertNull($seen->request);
        self::assertSame([], $port->resolveCalls);
    }

    #[Test]
    public function namingTheModelMakesTheSameHintBindAndAuthorize(): void
    {
        // The refusal is answerable, which is the test that it is a diagnosis
        // and not a wall: the message says `Router::model()`, and taking that
        // advice makes the route bind and the hook run — on the same `object`
        // hint that used to bypass both.
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->router->model('widget', WiredWidget::class);
        $harness->finishBoot();

        $harness->middleware->pipe(
            new AuthenticationMiddleware(new StubAuthManager(new Identity('user-9', 'Nine')), $harness->authState),
        );

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], ObjectHintedWidgetController::class),
        );

        self::assertSame(200, $seen->response->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $seen->request);

        $bound = $seen->request->getAttribute('_bound_models');
        self::assertIsArray($bound);
        self::assertInstanceOf(WiredWidget::class, $bound['widget'] ?? null);
        self::assertSame('user-9', $harness->gate->lastIdentityId, 'The hook must run for a bound model.');
    }

    #[Test]
    public function aScalarHintStillReachesTheHandlerWithItsRawSegment(): void
    {
        // The other family behind the same `isBuiltin()` test, and the one that
        // must not move: `string`, `int`, `array` and the rest accept no object,
        // so `/notes/{note}` with `string $note` is a route that binds nothing
        // and says so. It reaches the handler with no identity, under the
        // regulated preset, exactly as it documented.
        $harness = $this->wire(configBody: "'preset' => 'banking'");
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);
        $harness->finishBoot();

        $harness->middleware->pipe(
            new AuthenticationMiddleware(new StubAuthManager(new AnonymousIdentity()), $harness->authState),
        );

        $seen = $this->dispatchThroughKernelShape(
            $harness,
            $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], UntypedWidgetController::class),
        );

        self::assertSame(200, $seen->response->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $seen->request);
        self::assertSame([], $port->resolveCalls);
    }

    // -----------------------------------------------------------------
    // HTTP failure modes the composed middleware owns
    // -----------------------------------------------------------------

    #[Test]
    public function anUnknownKeyBecomesA404RatherThanA500(): void
    {
        // The composed middleware owns the HTTP failure modes, which is only
        // true if the wiring handed it a real config and a real hook. A miss
        // that surfaced as an uncaught exception would mean the assembly is
        // half-built.
        $harness = $this->wire();
        $harness->bindResolverPort(new RecordingModelResolver(found: false));
        $harness->finishBoot();

        $matched = $this->matchedRoute('/widgets/{widget}', ['widget' => '404']);

        // An authenticated caller, because the unconfigured posture is the
        // regulated one and this test is about the 404, not about the 401. The
        // wiring here is built with no config file at all, which used to select
        // the opt-in preset; it now selects the same enforcing preset the
        // shipped config file names, so the request has to name a caller to get
        // as far as the lookup this test is about.
        $harness->establishCaller(new Identity('user-9', 'Nine'));

        $response = $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/404', attributes: ['_route' => $matched]),
            static fn(): ResponseInterface => Response::text('handler'),
            $matched,
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertNotSame('handler', (string) $response->getBody());
    }

    // -----------------------------------------------------------------
    // Late composition
    // -----------------------------------------------------------------

    #[Test]
    public function readsExplicitBindingsRouteFilesDeclareAfterEveryWiringHasRun(): void
    {
        // Router::model() is called from routes/web.php and routes/api.php, which
        // the kernel loads AFTER the wiring loop. A BindingResolver built inside
        // wire() captures an empty list and every explicit binding is silently
        // discarded — the same "read the container too early" defect the deferred
        // step exists to remove.
        $harness = $this->wire();
        $port = new RecordingModelResolver();
        $harness->bindResolverPort($port);

        $harness->router->model('widget', WiredWidget::class);

        $harness->finishBoot();

        // The controller declares no type hint, so ONLY the explicit binding can
        // produce a binding here.
        $matched = $this->matchedRoute('/widgets/{widget}', ['widget' => '7'], UntypedWidgetController::class);

        $harness->establishCaller(new Identity('user-9', 'Nine'));

        (void) $harness->postRouting->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7', attributes: ['_route' => $matched]),
            static fn(): ResponseInterface => Response::text('handler'),
            $matched,
        );

        self::assertCount(1, $port->resolveCalls);
        self::assertSame(WiredWidget::class, $port->resolveCalls[0]['class']);
    }

    // -----------------------------------------------------------------
    // Authorization hook
    // -----------------------------------------------------------------

    #[Test]
    public function bindsThePolicyHookWhenAGateIsAvailable(): void
    {
        $harness = $this->wire();

        self::assertTrue($harness->container->has(AuthorizationHookInterface::class));
        self::assertInstanceOf(
            PolicyAuthorizationHook::class,
            $harness->container->get(AuthorizationHookInterface::class),
        );
    }

    #[Test]
    public function bindsNoHookAndComposesNothingWhenNoGateIsAvailable(): void
    {
        // With no Gate and no configured hook there is no way to authorize a
        // bound model. The pipeline therefore does not compose. The alternative —
        // a fallback hook that returns true — would hand every authenticated
        // caller every model a route names, one frame away from an
        // AuthorizationMiddleware that refuses a route declaring no permission.
        $harness = $this->wire(bindGate: false);
        $harness->bindResolverPort(new RecordingModelResolver());
        $harness->finishBoot();

        self::assertFalse($harness->container->has(AuthorizationHookInterface::class));
        self::assertTrue($harness->postRouting->isEmpty());
        self::assertSame([], $harness->resolvers->resolvers);
        self::assertFalse($harness->container->has(ModelBindingMiddleware::class));
    }

    #[Test]
    public function honoursTheAuthorizationHookNamedInConfiguration(): void
    {
        $harness = $this->wire(
            configBody: "'authorization_hook' => \\" . RecordingAuthorizationHook::class . '::class',
        );

        self::assertInstanceOf(
            RecordingAuthorizationHook::class,
            $harness->container->get(AuthorizationHookInterface::class),
        );
    }

    #[Test]
    public function leavesAnAuthorizationHookTheApplicationAlreadyBoundAlone(): void
    {
        $preBound = new RecordingAuthorizationHook();

        $harness = $this->wire(beforeWire: static function (Container $container) use ($preBound): void {
            $container->instance(AuthorizationHookInterface::class, $preBound);
        });

        self::assertSame($preBound, $harness->container->get(AuthorizationHookInterface::class));
    }

    // -----------------------------------------------------------------
    // Declared contract and boot position
    // -----------------------------------------------------------------

    #[Test]
    public function declaresTheSeamsItNeedsAndTheFeaturesItGates(): void
    {
        $contract = new ModelBindingWiring()->describeWiring();

        self::assertSame('model-binding', $contract->component);
        self::assertSame('model_binding.php', $contract->configFile);
        self::assertSame(ModelBindingConfig::class, $contract->configClass);
        self::assertSame([ModelBindingConfig::class], $contract->provides);
        self::assertContains(DeferredComposition::class, $contract->requires);
        self::assertContains(PostRoutingPipeline::class, $contract->requires);
        self::assertContains(ArgumentResolverRegistryInterface::class, $contract->requires);

        $gated = array_map(static fn(OptionalBinding $o): string => $o->binding, $contract->optional);
        self::assertContains(ModelResolverPort::class, $gated);
        self::assertContains(GateInterface::class, $gated);

        // Deliberately not security-flagged. SecurityPostureWiring evaluates the
        // degraded set from inside the wiring loop, before extension register()
        // has run, so ModelResolverPort is unbound there in every application —
        // a security flag would raise a universal posture FAIL and, with
        // enforcement on in production, abort correctly configured boots.
        $securityFlagged = array_map(
            static fn(OptionalBinding $o): string => $o->binding,
            array_values(array_filter($contract->optional, static fn(OptionalBinding $o): bool => $o->security)),
        );
        self::assertSame([], $securityFlagged);
    }

    #[Test]
    public function runsAfterTenancyAndBeforeSaga(): void
    {
        $classes = array_map(static fn(ServiceWiringInterface $w): string => $w::class, WiringList::default());

        $tenancy = array_search(TenancyWiring::class, $classes, true);
        $binding = array_search(ModelBindingWiring::class, $classes, true);
        $saga = array_search(SagaWiring::class, $classes, true);

        self::assertIsInt($tenancy);
        self::assertIsInt($binding);
        self::assertIsInt($saga);
        self::assertLessThan($binding, $tenancy, 'Tenant-scoped resolution needs TenancyWiring to have run.');
        self::assertLessThan($saga, $binding);
    }

    #[Test]
    public function isListedExactlyOnce(): void
    {
        $classes = array_filter(
            WiringList::default(),
            static fn(ServiceWiringInterface $w): bool => $w instanceof ModelBindingWiring,
        );

        self::assertCount(1, $classes);
    }

    // -----------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------

    /**
     * Boot the wiring the way the kernel does: seams bound first, config loaded
     * through the registrar, wire(), and — separately — the end-of-boot drain.
     *
     * @param (callable(Container): void)|null $beforeWire
     */
    private function wire(
        ?string $configBody = null,
        bool $bindGate = true,
        ?callable $beforeWire = null,
        bool $bindAuthState = true,
    ): ModelBindingHarness {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $postRouting = new PostRoutingPipeline($container);
        $resolvers = new ArgumentResolverChain();
        $deferred = new DeferredComposition();

        // AuthWiring binds this and pipes the AuthenticationMiddleware that
        // publishes into it. It is bound BEFORE wire() for the same reason the
        // real one is: the composition of the binding pipeline reads it at the
        // end of boot, and an application with no `auth` section has neither.
        $authState = new AuthenticationState();

        if ($bindAuthState) {
            $container->instance(AuthenticationState::class, $authState);
        }

        // The composition root binds these before any wiring runs.
        $container->instance(PostRoutingPipeline::class, $postRouting);
        $container->instance(ArgumentResolverChain::class, $resolvers);
        $container->instance(ArgumentResolverRegistryInterface::class, $resolvers);
        $container->instance(DeferredComposition::class, $deferred);

        // bind(), not instance(): resolving the definition is observable, which
        // is how the laziness of the hook binding is asserted.
        $gate = new RecordingGate();
        if ($bindGate) {
            $container->bind(GateInterface::class, static function () use ($gate): GateInterface {
                ++$gate->resolutions;

                return $gate;
            });
        }

        if ($beforeWire !== null) {
            $beforeWire($container);
        }

        $configPath = sys_get_temp_dir() . '/pulsar_model_binding_wiring_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);
        file_put_contents($configPath . '/app.php', '<?php return [];');
        file_put_contents($configPath . '/security.php', '<?php return [];');
        file_put_contents($configPath . '/observability.php', '<?php return [];');

        if ($configBody !== null) {
            file_put_contents($configPath . '/model_binding.php', "<?php return [{$configBody}];");
        }

        $configManager = new ConfigManager($configPath);
        $wiring = new ModelBindingWiring();
        ConfigLoaderRegistrar::register($configManager, [$wiring]);
        $configManager->load();

        $wiring->wire($container, $configManager, $middleware, new MiddlewareRegistry(), $router);

        return new ModelBindingHarness(
            $container,
            $router,
            $middleware,
            $postRouting,
            $resolvers,
            $deferred,
            $gate,
            $authState,
        );
    }

    /**
     * @param array<string, string> $parameters
     * @param class-string          $controller
     * @param array<string, mixed>  $attributes
     */
    private function matchedRoute(
        string $path,
        array $parameters,
        string $controller = TypedWidgetController::class,
        array $attributes = [],
    ): MatchedRoute {
        return new MatchedRoute(
            new Route([Method::GET], $path, [$controller, 'show'], attributes: $attributes),
            $parameters,
        );
    }

    /**
     * Dispatch a request through the frames the kernel dispatches, in the kernel's
     * order: global pipeline, then routing attaches `_route`, then route-level
     * middleware, then the post-routing pipeline, then the handler.
     *
     * Reproducing the nesting rather than calling the post-routing pipeline
     * directly is what makes the two auth shapes distinguishable at all: the
     * whole difference between them is which frame has run by the time the
     * binding middleware reads the request.
     */
    private function dispatchThroughKernelShape(
        ModelBindingHarness $harness,
        MatchedRoute $matched,
        ?MiddlewareInterface $routeMiddleware = null,
    ): DispatchOutcome {
        $handled = null;

        $response = $harness->middleware->dispatch(
            new ServerRequest(method: 'GET', uri: '/widgets/7'),
            function (ServerRequestInterface $request) use ($harness, $matched, $routeMiddleware, &$handled): ResponseInterface {
                // Where the kernel attaches it: inside dispatch, after the global
                // pipeline and before route middleware.
                $request = $request->withAttribute('_route', $matched);

                // A plain closure, not an arrow function: an arrow function
                // auto-captures BY VALUE, so the by-reference `use` below would
                // bind to that copy and the handler's request would never reach
                // the caller.
                $inner = static function (ServerRequestInterface $routed) use ($harness, $matched, &$handled): ResponseInterface {
                    return $harness->postRouting->dispatch(
                        $routed,
                        static function (ServerRequestInterface $bound) use (&$handled): ResponseInterface {
                            $handled = $bound;

                            return Response::text('handler');
                        },
                        $matched,
                    );
                };

                if ($routeMiddleware === null) {
                    return $inner($request);
                }

                $routePipeline = new MiddlewarePipeline($harness->container);
                $routePipeline->pipe($routeMiddleware);

                return $routePipeline->dispatch($request, $inner, $matched);
            },
        );

        return new DispatchOutcome($handled, $response);
    }
}

/**
 * What the handler saw, and what the client got. `request` is null when a frame
 * short-circuited before the handler — which is the assertion for every denial.
 */
final readonly class DispatchOutcome
{
    public function __construct(
        public ?ServerRequestInterface $request,
        public ResponseInterface $response,
    ) {}
}

/**
 * The pieces a boot produces, kept together so each test asserts on the real
 * objects the wiring wrote into rather than on a mock of them.
 */
final readonly class ModelBindingHarness
{
    public function __construct(
        public Container $container,
        public Router $router,
        public MiddlewarePipeline $middleware,
        public PostRoutingPipeline $postRouting,
        public ArgumentResolverChain $resolvers,
        public DeferredComposition $deferred,
        public RecordingGate $gate,
        public AuthenticationState $authState,
    ) {}

    /**
     * Stand in for the extension `register()` phase, which the kernel runs after
     * every wiring has finished.
     */
    public function bindResolverPort(ModelResolverPort $port): void
    {
        $this->container->instance(ModelResolverPort::class, $port);
    }

    /**
     * Stand in for the end of Kernel::boot(), where the deferred queue drains.
     */
    public function finishBoot(): void
    {
        $this->deferred->apply($this->container);
    }

    /**
     * Publish a caller the way the auth stack does, for the tests whose subject
     * is something other than authentication.
     *
     * A test that wants an identified caller cannot set the `_identity`
     * attribute any more, and that is the property under test elsewhere in this
     * file. It goes through the holder instead — which is exactly what
     * AuthenticationMiddleware does with the context it builds.
     */
    public function establishCaller(IdentityInterface $identity): void
    {
        $this->authState->establish(SecurityContext::established(
            new StubAuthManager($identity),
            new ServerRequest(method: 'GET', uri: '/'),
            $identity,
        ));
    }
}

final class WiredWidget
{
    public function __construct(public string $id = '7') {}
}

final class TypedWidgetController
{
    public function show(WiredWidget $widget): string
    {
        return $widget->id;
    }
}

final class UntypedWidgetController
{
    public function show(string $widget): string
    {
        return $widget;
    }
}

/**
 * The six declarations that accept an object and name no class. Each one used
 * to disable model binding, the regulated preset's identity requirement and the
 * authorization hook, silently, on a route that had declared a model.
 */
final class ObjectHintedWidgetController
{
    public function show(object $widget): string
    {
        return $widget::class;
    }
}

final class MixedHintedWidgetController
{
    public function show(mixed $widget): string
    {
        return get_debug_type($widget);
    }
}

final class UnionObjectHintedWidgetController
{
    public function show(object|string $widget): string
    {
        return get_debug_type($widget);
    }
}

final class IterableHintedWidgetController
{
    /**
     * @param iterable<mixed> $widget
     */
    public function show(iterable $widget): string
    {
        return get_debug_type($widget);
    }
}

final class NullableObjectHintedWidgetController
{
    public function show(?object $widget): string
    {
        return get_debug_type($widget);
    }
}

final class CallableHintedWidgetController
{
    public function show(callable $widget): string
    {
        return get_debug_type($widget);
    }
}

/**
 * An ordinary application middleware that names the caller.
 *
 * It writes every attribute the binding layer used to consult, including a
 * SecurityContext of its own — the narrowest of the three old channels, and the
 * one a partial fix would leave open. Nothing here needs container access or
 * any privilege: piping a middleware is what applications and extensions do.
 */
final readonly class IdentityAttributeSpoofingMiddleware implements MiddlewareInterface
{
    public function __construct(private IdentityInterface $identity) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $request->withAttribute('_identity', $this->identity);
        $request = $request->withAttribute('identity', $this->identity);
        $request = $request->withAttribute('_security_context', SecurityContext::established(
            new StubAuthManager($this->identity),
            $request,
            $this->identity,
        ));

        return $handler->handle($request);
    }
}

final class RecordingModelResolver implements ModelResolverPort
{
    /** @var list<array{class: string, key: string, value: string|int, context: ResolutionContext}> */
    public array $resolveCalls = [];

    /** @var list<array{class: string, relation: string, parent: object}> */
    public array $scopedCalls = [];

    /**
     * @param bool $found Whether the store holds the record. False is the miss
     *                    every real resolver reports for an unknown key.
     */
    public function __construct(private readonly bool $found = true) {}

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $this->resolveCalls[] = ['class' => $modelClass, 'key' => $keyName, 'value' => $keyValue, 'context' => $context];

        return $this->found ? new WiredWidget((string) $keyValue) : null;
    }

    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): ?object {
        $this->scopedCalls[] = ['class' => $modelClass, 'relation' => $relation, 'parent' => $parent];

        return $this->found ? new WiredWidget((string) $keyValue) : null;
    }
}

final class RecordingGate implements GateInterface
{
    public ?string $lastIdentityId = null;

    /**
     * How often the container resolved the Gate definition, which is how the
     * tests observe that the hook binding stays lazy.
     */
    public int $resolutions = 0;

    #[Override]
    public function allows(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        $this->lastIdentityId = $identity->id();

        return true;
    }

    #[Override]
    public function denies(IdentityInterface $identity, string $permission, ?PolicyContext $context = null): bool
    {
        return !$this->allows($identity, $permission, $context);
    }
}

final class RecordingAuthorizationHook implements AuthorizationHookInterface
{
    #[Override]
    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        return true;
    }
}

/**
 * The guard stack behind both auth shapes, stubbed at the one seam that matters.
 *
 * The middleware under test is the REAL AuthenticationMiddleware and the REAL
 * AuthorizationMiddleware; only the answer the guards would give is stubbed. A
 * stand-in for the middleware itself is what let the previous version of this
 * suite assert that a request carries an authenticated `_identity` before
 * anything has authenticated — which no real boot produces.
 */
final class StubAuthManager implements AuthManagerInterface
{
    /**
     * How often the guards were actually consulted. One per request is correct;
     * two means something bypassed the SecurityContext's memoisation.
     */
    public int $authenticateCalls = 0;

    public function __construct(private readonly IdentityInterface $identity = new AnonymousIdentity()) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): IdentityInterface
    {
        ++$this->authenticateCalls;

        return $this->identity;
    }

    #[Override]
    public function guard(string $name): GuardInterface
    {
        throw new LogicException('Nothing in the binding path may reach for a named guard.');
    }

    #[Override]
    public function defaultGuard(): string
    {
        return 'stub';
    }
}
