<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Core\Controller\ArgumentResolverChain;
use Pulsar\Core\Controller\ArgumentResolverLifecycleException;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Controller\HandlerArgumentResolverInterface;
use Pulsar\Core\Controller\HandlerDescriptor;
use Pulsar\Core\Controller\HandlerParameter;
use Pulsar\Core\Controller\HandlerSignature;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Http\Middleware\PostRoutingPipeline;
use Pulsar\Routing\Binding\BindingProvenance;
use Pulsar\Routing\Binding\BoundModelArgumentResolver;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function ini_get;

/**
 * The kernel's handler-argument seam.
 *
 * The first half pins the pre-existing behaviour for every handler shape the
 * framework supports: with no resolver registered — and with a resolver that
 * claims nothing — the argument list must be exactly what it was before the
 * chain existed. A framework whose controller invocation changes shape breaks
 * every application, so those cases assert the full argument list rather than a
 * status code.
 *
 * The second half exercises the seam itself.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(ArgumentResolverChain::class)]
#[CoversClass(HandlerDescriptor::class)]
#[CoversClass(HandlerSignature::class)]
#[CoversClass(HandlerParameter::class)]
#[CoversClass(BoundModelArgumentResolver::class)]
#[CoversClass(PostRoutingPipeline::class)]
final class KernelArgumentResolverTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Unchanged behaviour: no resolver registered
    // -----------------------------------------------------------------------

    #[Test]
    public function closureHandlerStillReceivesRequestAndRawParameterArray(): void
    {
        $seen = new ArgRecorder();

        $kernel = new Kernel();
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/closure/{id}',
            handler: static function (ServerRequestInterface $request, array $params) use ($seen): ResponseInterface {
                $seen->arguments = [$request, $params];

                return Response::html('ok');
            },
        ));

        $kernel->handle($this->request('/closure/42'));

        self::assertCount(2, $seen->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $seen->arguments[0]);
        self::assertSame(['id' => '42'], $seen->arguments[1]);
    }

    #[Test]
    public function controllerWithNoParametersReceivesNoArguments(): void
    {
        $controller = $this->dispatch('/none', 'noParams');

        self::assertSame([], $controller->arguments);
    }

    #[Test]
    public function controllerWantingOnlyTheRequestReceivesOnlyTheRequest(): void
    {
        $controller = $this->dispatch('/request-only', 'requestOnly');

        self::assertCount(1, $controller->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $controller->arguments[0]);
    }

    #[Test]
    public function legacyArrayParameterStillReceivesTheRawRouteParameters(): void
    {
        $controller = $this->dispatch('/legacy/abc', 'requestAndArray', '/legacy/{slug}');

        self::assertCount(2, $controller->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $controller->arguments[0]);
        self::assertSame(['slug' => 'abc'], $controller->arguments[1]);
    }

    #[Test]
    public function legacyArrayParameterWithoutRequestStillReceivesOnlyTheArray(): void
    {
        $controller = $this->dispatch('/legacy-no-request/abc', 'arrayOnly', '/legacy-no-request/{slug}');

        self::assertSame([['slug' => 'abc']], $controller->arguments);
    }

    #[Test]
    public function namedRouteParametersAreSpreadPositionally(): void
    {
        $controller = $this->dispatch('/named/abc', 'requestAndScalar', '/named/{slug}');

        self::assertCount(2, $controller->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $controller->arguments[0]);
        self::assertSame('abc', $controller->arguments[1]);
    }

    #[Test]
    public function namedRouteParametersAreSpreadWithoutALeadingRequest(): void
    {
        $controller = $this->dispatch('/scalar-only/abc', 'scalarOnly', '/scalar-only/{slug}');

        self::assertSame(['abc'], $controller->arguments);
    }

    #[Test]
    public function aParameterWithNoRouteValueFallsBackToItsDefault(): void
    {
        $controller = $this->dispatch('/default/abc', 'scalarWithDefault', '/default/{slug}');

        self::assertCount(3, $controller->arguments);
        self::assertSame('abc', $controller->arguments[1]);
        self::assertSame('overview', $controller->arguments[2]);
    }

    #[Test]
    public function invokableControllerReceivesNamedRouteParameters(): void
    {
        $kernel = new Kernel();
        $controller = new ArgInvokableController();
        $kernel->container()->instance(ArgInvokableController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/invokable/{slug}',
            handler: ArgInvokableController::class,
        ));

        $kernel->handle($this->request('/invokable/abc'));

        self::assertCount(2, $controller->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $controller->arguments[0]);
        self::assertSame('abc', $controller->arguments[1]);
    }

    /**
     * A parameter nothing can fill is OMITTED, and the kernel keeps going.
     *
     * The handler runs with `$present`'s route value delivered into `$missing`.
     * That is a latent bug in the handler's declaration — but it is the
     * application's bug, in the application's code, and it is how this framework
     * has called controllers since its first release.
     *
     * A revision of this seam once truncated the list here instead. It read as
     * strictly safer and it was not: measured differentially against the
     * previous kernel, eleven ordinary handler shapes went from 200 to 500, none
     * of them involving a resolver or a bound model.
     * {@see \Pulsar\Tests\Unit\Core\Controller\HandlerInvocationContractTest}
     * enumerates them. What protects a resolver's value is not truncation but
     * delivery by name, asserted directly below.
     */
    #[Test]
    public function anUnfillableParameterIsOmittedAndTheHandlerStillRuns(): void
    {
        $kernel = new Kernel();
        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/gap/{present}',
            handler: [ArgTestController::class, 'gap'],
        ));

        $response = $kernel->handle($this->request('/gap/p-value'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(3, $controller->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $controller->arguments[0]);
        self::assertSame('p-value', $controller->arguments[1]);
        self::assertSame('fallback', $controller->arguments[2]);
    }

    /**
     * The same shape, once a resolver's value is in the list.
     *
     * Positional delivery would slide the claimed value into `$missing` — a
     * value the framework produced under an authorization decision, handed to a
     * parameter that never asked for it. The kernel switches the whole call to
     * named arguments, so the claim reaches the parameter it names or the
     * handler is not entered at all.
     */
    #[Test]
    public function aClaimedValueIsNeverSlidIntoAnUnfillableParametersSlot(): void
    {
        $kernel = new Kernel();
        $this->registry($kernel)->add(new RecordingArgumentResolver(['present' => 'claimed']));

        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/gap-claimed/{present}',
            handler: [ArgTestController::class, 'gap'],
        ));

        $response = $this->withErrorLogSilenced(
            fn(): ResponseInterface => $kernel->handle($this->request('/gap-claimed/p-value')),
        );

        self::assertSame([], $controller->arguments, 'the handler must not run on a misaligned list');
        self::assertSame(500, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Unchanged behaviour: a resolver is registered but claims nothing
    // -----------------------------------------------------------------------

    #[Test]
    public function aResolverThatClaimsNothingLeavesTheArgumentListUntouched(): void
    {
        $kernel = new Kernel();
        $this->registry($kernel)->add(new NullArgumentResolver());

        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/quiet/{slug}',
            handler: [ArgTestController::class, 'scalarWithDefault'],
        ));

        $kernel->handle($this->request('/quiet/abc'));

        self::assertCount(3, $controller->arguments);
        self::assertSame('abc', $controller->arguments[1]);
        self::assertSame('overview', $controller->arguments[2]);
    }

    #[Test]
    public function theLegacyArrayPathNeverConsultsTheChain(): void
    {
        $kernel = new Kernel();
        $resolver = new RecordingArgumentResolver(['slug' => 'claimed']);
        $this->registry($kernel)->add($resolver);

        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/legacy-chain/{slug}',
            handler: [ArgTestController::class, 'requestAndArray'],
        ));

        $kernel->handle($this->request('/legacy-chain/abc'));

        self::assertFalse($resolver->called, 'a handler asking for the raw parameter array bypasses the chain');
        self::assertSame(['slug' => 'abc'], $controller->arguments[1]);
    }

    // -----------------------------------------------------------------------
    // The seam
    // -----------------------------------------------------------------------

    #[Test]
    public function aClaimedParameterOverridesTheRouteParameterOfTheSameName(): void
    {
        $kernel = new Kernel();
        $this->registry($kernel)->add(new RecordingArgumentResolver(['slug' => 'claimed']));

        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/claimed/{slug}',
            handler: [ArgTestController::class, 'requestAndScalar'],
        ));

        $kernel->handle($this->request('/claimed/abc'));

        self::assertSame('claimed', $controller->arguments[1]);
    }

    /**
     * A key present with a null value is a claim, so it must beat the route
     * parameter and the declared default rather than falling through to them.
     */
    #[Test]
    public function aNullClaimIsPassedThroughInsteadOfTheDefault(): void
    {
        $kernel = new Kernel();
        $this->registry($kernel)->add(new RecordingArgumentResolver(['tab' => null]));

        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/nullable/{slug}',
            handler: [ArgTestController::class, 'nullableWithDefault'],
        ));

        $kernel->handle($this->request('/nullable/abc'));

        self::assertCount(3, $controller->arguments);
        self::assertNull($controller->arguments[2]);
    }

    #[Test]
    public function aHandlerTypeHintingABoundEntityReceivesTheEntity(): void
    {
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/users/u-1',
            pattern: '/users/{user}',
            method: 'entity',
            models: ['user' => $entity],
        );

        self::assertCount(2, $controller->arguments);
        self::assertInstanceOf(ServerRequestInterface::class, $controller->arguments[0]);
        self::assertSame($entity, $controller->arguments[1]);
    }

    #[Test]
    public function aHandlerMixingABoundEntityAndARawParameterGetsBothInOrder(): void
    {
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/users/u-1/tabs/settings',
            pattern: '/users/{user}/tabs/{tab}',
            method: 'entityThenScalar',
            models: ['user' => $entity],
        );

        self::assertCount(3, $controller->arguments);
        self::assertSame($entity, $controller->arguments[1]);
        self::assertSame('settings', $controller->arguments[2]);
    }

    #[Test]
    public function aRawParameterDeclaredBeforeTheEntityKeepsItsPosition(): void
    {
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/tabs/settings/users/u-1',
            pattern: '/tabs/{tab}/users/{user}',
            method: 'scalarThenEntity',
            models: ['user' => $entity],
        );

        self::assertCount(3, $controller->arguments);
        self::assertSame('settings', $controller->arguments[1]);
        self::assertSame($entity, $controller->arguments[2]);
    }

    /**
     * A route parameter whose name collides with a scalar handler argument must
     * keep receiving the raw string. Handing it the object would raise a
     * TypeError at the call site and turn a working route into a 500.
     */
    #[Test]
    public function aBoundModelIsNotHandedToAScalarParameterOfTheSameName(): void
    {
        $controller = $this->dispatchBound(
            path: '/scalar/u-1',
            pattern: '/scalar/{user}',
            method: 'scalarNamedUser',
            models: ['user' => new ArgTestEntity('u-1')],
        );

        self::assertSame('u-1', $controller->arguments[1]);
    }

    /**
     * A union naming the bound class DECLARES the entity, and used not to
     * receive it.
     *
     * HandlerParameter::$type is null for a union, so the resolver's
     * `builtin ? null : type` test read `ArgTestEntity|string $user` as an
     * untyped parameter, claimed nothing, and let the kernel fill the slot from
     * the route parameters. The handler was then handed the raw URL string on
     * the very parameter whose entity type hint is what makes the route read as
     * resolved and authorized — the containment check and the policy check had
     * both run, and their result was discarded one frame before the handler.
     */
    #[Test]
    public function aUnionDeclaringTheBoundEntityReceivesTheEntityRatherThanTheRawString(): void
    {
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/union/u-1',
            pattern: '/union/{user}',
            method: 'unionEntityOrScalar',
            models: ['user' => $entity],
        );

        self::assertCount(2, $controller->arguments);
        self::assertSame($entity, $controller->arguments[1]);
        self::assertNotSame('u-1', $controller->arguments[1]);
    }

    #[Test]
    public function aUnionThatDoesNotNameTheBoundEntityStillReceivesTheRawString(): void
    {
        // The control, and the reason the fix is a type test rather than a
        // "unions always take the model" rule: `ArgUnrelatedEntity|string` does
        // not accept an ArgTestEntity, so claiming it would be the TypeError the
        // original check existed to prevent.
        $controller = $this->dispatchBound(
            path: '/union-other/u-1',
            pattern: '/union-other/{user}',
            method: 'unionOfOtherEntityOrScalar',
            models: ['user' => new ArgTestEntity('u-1')],
        );

        self::assertCount(2, $controller->arguments);
        self::assertSame('u-1', $controller->arguments[1]);
    }

    #[Test]
    public function anIntersectionSatisfiedByTheBoundEntityReceivesTheEntity(): void
    {
        // An intersection reaches the resolver as a null type for the same
        // reason a union does, and every part of it has to match — the check is
        // "all of these", not "any of these".
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/intersection/u-1',
            pattern: '/intersection/{user}',
            method: 'intersectionEntity',
            models: ['user' => $entity],
        );

        self::assertCount(2, $controller->arguments);
        self::assertSame($entity, $controller->arguments[1]);
    }

    #[Test]
    public function aBoundModelOfTheWrongTypeIsNotClaimed(): void
    {
        $controller = $this->dispatchBound(
            path: '/wrong-type/u-1',
            pattern: '/wrong-type/{user}',
            method: 'scalarNamedUser',
            models: ['user' => new ArgUnrelatedEntity()],
        );

        // Nothing claimed the parameter, so the raw route value is spread as
        // before: exactly the behaviour a request that never reached the binding
        // middleware would produce.
        self::assertSame('u-1', $controller->arguments[1]);
    }

    /**
     * `object` and `mixed` are builtin type names, and every object satisfies
     * both. A resolver that read "builtin" as "accepts no object" dropped the
     * resolved, authorized model on exactly the two declarations that could
     * never have rejected it: the kernel filled the slot from the route
     * parameters and the handler got the raw URL string.
     */
    #[Test]
    public function aParameterTypedObjectReceivesTheBoundEntity(): void
    {
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/object/u-1',
            pattern: '/object/{user}',
            method: 'objectTyped',
            models: ['user' => $entity],
        );

        self::assertCount(2, $controller->arguments);
        self::assertSame($entity, $controller->arguments[1]);
    }

    #[Test]
    public function aParameterTypedMixedReceivesTheBoundEntity(): void
    {
        $entity = new ArgTestEntity('u-1');

        $controller = $this->dispatchBound(
            path: '/mixed/u-1',
            pattern: '/mixed/{user}',
            method: 'mixedTyped',
            models: ['user' => $entity],
        );

        self::assertCount(2, $controller->arguments);
        self::assertSame($entity, $controller->arguments[1]);
    }

    /**
     * THE FORGED ATTRIBUTE, through a real dispatch.
     *
     * A middleware inner to the binding one writes `_bound_models` with an
     * object of its own. Nothing recorded resolving it, so the resolver claims
     * nothing and the parameter falls through to the route value — the union
     * makes that visible as a value rather than as an ArgumentCountError.
     *
     * Before provenance, this object arrived SEALED: undisplaceable, on the
     * parameter whose entity type hint is what makes the route read as
     * authorized.
     */
    #[Test]
    public function aForgedBoundModelsAttributeReachesNoHandlerParameter(): void
    {
        $forged = new ArgTestEntity('forged');

        $kernel = new Kernel();
        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);

        // Two records that never meet: the attacker's middleware writes into one
        // and the resolver reads the other, which is the position anything but
        // the framework's own binder is in.
        $pipeline->pipe(new BoundModelsStubMiddleware(['user' => $forged], new BindingProvenance()));
        $this->registry($kernel)->add(new BoundModelArgumentResolver(new BindingProvenance()));

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/forged/{user}',
            handler: [ArgTestController::class, 'unionEntityOrScalar'],
        ));

        $kernel->handle($this->request('/forged/u-1'));

        self::assertSame('u-1', $controller->arguments[1]);
    }

    /**
     * THE REPLACEMENT ATTEMPT, from where an attacker actually stands.
     *
     * The kernel publishes the concrete chain in the container so a wiring can
     * append to it. Restoring it used to be a public method on that same object,
     * so anything that could reach the container could drop the bound-model
     * resolver — and with the resolver gone there is no seal left to displace.
     *
     * The kernel took the one lifecycle handle in its constructor, before any
     * wiring, extension or route file ran, so the attempt is refused and the
     * request that follows is served with the bound model intact.
     */
    #[Test]
    public function theResolverChainCannotBeReplacedThroughTheContainer(): void
    {
        $entity = new ArgTestEntity('u-1');

        $kernel = new Kernel();
        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);

        $provenance = new BindingProvenance();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);
        $pipeline->pipe(new BoundModelsStubMiddleware(['user' => $entity], $provenance));
        $this->registry($kernel)->add(new BoundModelArgumentResolver($provenance));

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/guarded/{user}',
            handler: [ArgTestController::class, 'entity'],
        ));

        /** @var ArgumentResolverChain $chain */
        $chain = $kernel->container()->get(ArgumentResolverChain::class);

        try {
            (void) $chain->issueLifecycle();
            self::fail('The chain must refuse a second lifecycle handle.');
        } catch (ArgumentResolverLifecycleException $e) {
            self::assertStringContainsString('already issued', $e->getMessage());
        }

        self::assertCount(1, $chain->resolvers);

        $kernel->handle($this->request('/guarded/u-1'));

        self::assertCount(2, $controller->arguments);
        self::assertSame($entity, $controller->arguments[1]);
    }

    #[Test]
    public function withoutTheBoundModelsAttributeTheResolverClaimsNothing(): void
    {
        $kernel = new Kernel();
        $this->registry($kernel)->add(new BoundModelArgumentResolver(new BindingProvenance()));

        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/unbound/{slug}',
            handler: [ArgTestController::class, 'requestAndScalar'],
        ));

        $kernel->handle($this->request('/unbound/abc'));

        self::assertSame('abc', $controller->arguments[1]);
    }

    // -----------------------------------------------------------------------
    // The post-routing pipeline
    // -----------------------------------------------------------------------

    #[Test]
    public function thePostRoutingPipelineIsEmptyOnAFreshKernel(): void
    {
        $kernel = new Kernel();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);

        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function postRoutingMiddlewareRunsAfterTheRouteHasMatched(): void
    {
        $kernel = new Kernel();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);
        $observer = new RouteAttributeObserverMiddleware();
        $pipeline->pipe($observer);

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/observed/{slug}',
            handler: static fn(ServerRequestInterface $request): ResponseInterface => Response::html('ok'),
        ));

        $kernel->handle($this->request('/observed/abc'));

        self::assertTrue($observer->sawRoute, 'post-routing middleware must observe the _route attribute');
    }

    #[Test]
    public function postRoutingMiddlewareRunsInsideRouteMiddleware(): void
    {
        $kernel = new Kernel();
        $order = new ArgRecorder();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);
        $pipeline->pipe(new OrderRecordingMiddleware($order, 'post-routing'));

        $kernel->container()->instance(
            OrderRecordingMiddleware::class,
            new OrderRecordingMiddleware($order, 'route'),
        );

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/ordered',
            handler: static fn(ServerRequestInterface $request): ResponseInterface => Response::html('ok'),
            middleware: [OrderRecordingMiddleware::class],
        ));

        $kernel->handle($this->request('/ordered'));

        self::assertSame(['route', 'post-routing'], $order->arguments);
    }

    /**
     * Both seams are populated during boot, so shutdown() must restore the
     * pre-boot baseline. Without it a recycled worker stacks a second copy of
     * every resolver and every post-routing middleware, resolving each bound
     * model twice and running each authorization check twice per request.
     */
    #[Test]
    public function shutdownRestoresTheBootBaselineOfBothSeams(): void
    {
        $chain = new ArgumentResolverChain();
        $kernel = new Kernel(argumentResolvers: $chain);

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);

        $kernel->boot();

        $chain->add(new NullArgumentResolver());
        $pipeline->pipe(new RouteAttributeObserverMiddleware());

        self::assertCount(1, $chain->resolvers);
        self::assertFalse($pipeline->isEmpty());

        $kernel->shutdown();

        self::assertSame([], $chain->resolvers);
        self::assertTrue($pipeline->isEmpty());
    }

    #[Test]
    public function resolversRegisteredBeforeBootSurviveShutdown(): void
    {
        $chain = new ArgumentResolverChain();
        $resolver = new NullArgumentResolver();
        $chain->add($resolver);

        $kernel = new Kernel(argumentResolvers: $chain);
        $kernel->boot();
        $kernel->shutdown();

        self::assertSame([$resolver], $chain->resolvers);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function registry(Kernel $kernel): ArgumentResolverRegistryInterface
    {
        /** @var ArgumentResolverRegistryInterface $registry */
        $registry = $kernel->container()->get(ArgumentResolverRegistryInterface::class);

        return $registry;
    }

    private function dispatch(string $path, string $method, ?string $pattern = null): ArgTestController
    {
        $kernel = new Kernel();
        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);
        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: $pattern ?? $path,
            handler: [ArgTestController::class, $method],
        ));

        $kernel->handle($this->request($path));

        return $controller;
    }

    /**
     * Dispatch through the production shape of route model binding: a
     * post-routing middleware attaches `_bound_models`, and
     * {@see BoundModelArgumentResolver} hands them to the handler.
     *
     * @param array<string, object> $models
     */
    private function dispatchBound(string $path, string $pattern, string $method, array $models): ArgTestController
    {
        $kernel = new Kernel();
        $controller = new ArgTestController();
        $kernel->container()->instance(ArgTestController::class, $controller);

        // One record, written by the middleware standing in for the binder and
        // read by the resolver, exactly as ModelBindingWiring composes them.
        $provenance = new BindingProvenance();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);
        $pipeline->pipe(new BoundModelsStubMiddleware($models, $provenance));

        $this->registry($kernel)->add(new BoundModelArgumentResolver($provenance));

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: $pattern,
            handler: [ArgTestController::class, $method],
        ));

        $kernel->handle($this->request($path));

        return $controller;
    }

    private function request(string $path): ServerRequestInterface
    {
        return new ServerRequest(method: 'GET', uri: 'http://localhost' . $path);
    }

    /**
     * The kernel reports an unhandled throwable through error_log(), which the
     * CLI SAPI writes to the runner's own output. A test that deliberately
     * provokes a 500 must not print, so the sink is pointed at a temporary file
     * for the duration of the dispatch and restored afterwards.
     *
     * @param callable(): ResponseInterface $dispatch
     */
    private function withErrorLogSilenced(callable $dispatch): ResponseInterface
    {
        $previous = ini_get('error_log');
        $sink = tempnam(sys_get_temp_dir(), 'pulsar-args-');
        self::assertIsString($sink, 'could not create a sink for error_log');

        ini_set('error_log', $sink);

        try {
            return $dispatch();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);

            if (is_file($sink)) {
                unlink($sink);
            }
        }
    }
}

/** Shared mutable sink so a closure handler can report what it received. */
final class ArgRecorder
{
    /** @var list<mixed> */
    public array $arguments = [];
}

interface ArgTestMarker {}

final class ArgTestEntity implements ArgTestMarker
{
    public function __construct(public string $id) {}
}

final class ArgUnrelatedEntity {}

final class ArgTestController
{
    /** @var list<mixed> */
    public array $arguments = [];

    public function noParams(): ResponseInterface
    {
        $this->arguments = [];

        return Response::html('ok');
    }

    public function requestOnly(ServerRequestInterface $request): ResponseInterface
    {
        $this->arguments = [$request];

        return Response::html('ok');
    }

    /**
     * @param array<string, string> $params
     */
    public function requestAndArray(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $this->arguments = [$request, $params];

        return Response::html('ok');
    }

    /**
     * @param array<string, string> $params
     */
    public function arrayOnly(array $params): ResponseInterface
    {
        $this->arguments = [$params];

        return Response::html('ok');
    }

    public function requestAndScalar(ServerRequestInterface $request, string $slug): ResponseInterface
    {
        $this->arguments = [$request, $slug];

        return Response::html('ok');
    }

    public function scalarOnly(string $slug): ResponseInterface
    {
        $this->arguments = [$slug];

        return Response::html('ok');
    }

    public function scalarWithDefault(
        ServerRequestInterface $request,
        string $slug,
        string $tab = 'overview',
    ): ResponseInterface {
        $this->arguments = [$request, $slug, $tab];

        return Response::html('ok');
    }

    public function nullableWithDefault(
        ServerRequestInterface $request,
        string $slug,
        ?string $tab = 'overview',
    ): ResponseInterface {
        $this->arguments = [$request, $slug, $tab];

        return Response::html('ok');
    }

    public function gap(
        ServerRequestInterface $request,
        string $missing,
        string $present = 'fallback',
    ): ResponseInterface {
        $this->arguments = [$request, $missing, $present];

        return Response::html('ok');
    }

    public function entity(ServerRequestInterface $request, ArgTestEntity $user): ResponseInterface
    {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }

    public function entityThenScalar(
        ServerRequestInterface $request,
        ArgTestEntity $user,
        string $tab,
    ): ResponseInterface {
        $this->arguments = [$request, $user, $tab];

        return Response::html('ok');
    }

    public function scalarThenEntity(
        ServerRequestInterface $request,
        string $tab,
        ArgTestEntity $user,
    ): ResponseInterface {
        $this->arguments = [$request, $tab, $user];

        return Response::html('ok');
    }

    public function scalarNamedUser(ServerRequestInterface $request, string $user): ResponseInterface
    {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }

    public function unionEntityOrScalar(
        ServerRequestInterface $request,
        ArgTestEntity|string $user,
    ): ResponseInterface {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }

    public function unionOfOtherEntityOrScalar(
        ServerRequestInterface $request,
        ArgUnrelatedEntity|string $user,
    ): ResponseInterface {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }

    public function intersectionEntity(
        ServerRequestInterface $request,
        ArgTestEntity&ArgTestMarker $user,
    ): ResponseInterface {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }

    public function objectTyped(ServerRequestInterface $request, object $user): ResponseInterface
    {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }

    public function mixedTyped(ServerRequestInterface $request, mixed $user): ResponseInterface
    {
        $this->arguments = [$request, $user];

        return Response::html('ok');
    }
}

final class ArgInvokableController
{
    /** @var list<mixed> */
    public array $arguments = [];

    public function __invoke(ServerRequestInterface $request, string $slug): ResponseInterface
    {
        $this->arguments = [$request, $slug];

        return Response::html('ok');
    }
}

/** Claims nothing, ever: the shape every well-behaved resolver takes on a miss. */
final readonly class NullArgumentResolver implements HandlerArgumentResolverInterface
{
    /**
     * @param array<string, string> $routeParameters
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function resolve(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array {
        return [];
    }
}

final class RecordingArgumentResolver implements HandlerArgumentResolverInterface
{
    public bool $called = false;

    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(private readonly array $claims) {}

    /**
     * @param array<string, string> $routeParameters
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function resolve(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array {
        $this->called = true;

        return $this->claims;
    }
}

/**
 * Stands in for ModelBindingMiddleware: attaches already-resolved models, and
 * records them in the provenance the binder would have written, for the
 * parameters and URL values this route matched. Both halves are needed —
 * BoundModelArgumentResolver seals nothing it cannot trace back to a binding
 * pass, so a stub that only set the attribute would exercise the forgery path.
 */
final readonly class BoundModelsStubMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, object> $models
     */
    public function __construct(
        private array $models,
        private BindingProvenance $provenance,
    ) {}

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        /** @var MatchedRoute|null $route */
        $route = $request->getAttribute('_route');

        if ($route !== null) {
            foreach ($this->models as $name => $model) {
                $routeValue = $route->parameter($name);

                if ($routeValue !== null) {
                    $this->provenance->record($model, $route, $name, $routeValue);
                }
            }
        }

        return $handler->handle($request->withAttribute('_bound_models', $this->models));
    }
}

final class RouteAttributeObserverMiddleware implements MiddlewareInterface
{
    public bool $sawRoute = false;

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $this->sawRoute = $request->getAttribute('_route') !== null;

        return $handler->handle($request);
    }
}

final readonly class OrderRecordingMiddleware implements MiddlewareInterface
{
    public function __construct(private ArgRecorder $recorder, private string $label) {}

    #[Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $this->recorder->arguments[] = $this->label;

        return $handler->handle($request);
    }
}
