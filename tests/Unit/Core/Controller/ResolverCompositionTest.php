<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Controller;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Core\Controller\ArgumentResolverChain;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Controller\HandlerArgumentResolverInterface;
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

use function is_a;

/**
 * Composition of two independent resolvers on one handler.
 *
 * ADR-0044 opened the chain so that route model binding is one contributor
 * among several. That promise only holds if a second resolver can be added
 * without changing what the first one supplies. These tests dispatch through a
 * real kernel with both a bound-model resolver and a service resolver
 * registered, in BOTH registration orders, and assert the handler received
 * every argument — the failure mode is not a wrong value but a 500 from a
 * TypeError at the call site, which a status-code-only assertion would report
 * as a routing problem.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(ArgumentResolverChain::class)]
#[CoversClass(BoundModelArgumentResolver::class)]
final class ResolverCompositionTest extends TestCase
{
    /**
     * The shape ADR-0044 exists to enable: one bound model, one injected
     * service, one raw route parameter, on a handler that names all three.
     */
    #[Test]
    public function aHandlerTakingABoundModelAServiceAndARawParameterReceivesAllThree(): void
    {
        foreach ($this->bothRegistrationOrders() as $label => $boundFirst) {
            $entity = new CompositionEntity('e-1');
            $service = new InMemoryCompositionService();

            $controller = $this->dispatch(
                method: 'entityServiceRaw',
                pattern: '/a/{bound}/raw/{raw}',
                path: '/a/e-1/raw/xyz',
                models: ['bound' => $entity],
                service: $service,
                boundFirst: $boundFirst,
            );

            self::assertSame(200, $controller->status, $label . ': the route must not 500');
            self::assertSame([$entity, $service, 'xyz'], $controller->arguments, $label);
        }
    }

    /**
     * The same three parameters, declared with the service FIRST.
     *
     * The bound-model resolver walked the signature in declaration order and,
     * on reaching a parameter it could not fill from the route, stopped
     * claiming. It could not see that another resolver fills that parameter, so
     * the model declared after it was never claimed, the raw route string was
     * spread into its slot, and the handler died with a TypeError.
     */
    #[Test]
    public function aServiceDeclaredBeforeTheBoundModelDoesNotCostTheModelItsClaim(): void
    {
        foreach ($this->bothRegistrationOrders() as $label => $boundFirst) {
            $entity = new CompositionEntity('e-1');
            $service = new InMemoryCompositionService();

            $controller = $this->dispatch(
                method: 'serviceEntityRaw',
                pattern: '/b/{bound}/raw/{raw}',
                path: '/b/e-1/raw/xyz',
                models: ['bound' => $entity],
                service: $service,
                boundFirst: $boundFirst,
            );

            self::assertSame(200, $controller->status, $label . ': the route must not 500');
            self::assertSame([$service, $entity, 'xyz'], $controller->arguments, $label);
        }
    }

    /**
     * A service declared BETWEEN two bound models. The second model is the one a
     * scoped binding authorized, so losing its claim hands the handler the raw
     * identifier from the URL in place of the object the middleware vetted.
     */
    #[Test]
    public function aServiceBetweenTwoBoundModelsCostsNeitherOfThemItsClaim(): void
    {
        foreach ($this->bothRegistrationOrders() as $label => $boundFirst) {
            $first = new CompositionEntity('e-1');
            $second = new CompositionEntity('e-2');
            $service = new InMemoryCompositionService();

            $controller = $this->dispatch(
                method: 'entityServiceEntity',
                pattern: '/c/{first}/second/{second}',
                path: '/c/e-1/second/e-2',
                models: ['first' => $first, 'second' => $second],
                service: $service,
                boundFirst: $boundFirst,
            );

            self::assertSame(200, $controller->status, $label . ': the route must not 500');
            self::assertSame([$first, $service, $second], $controller->arguments, $label);
        }
    }

    /**
     * The contested name, through a real kernel, in the order a real boot
     * produces.
     *
     * `ModelBindingWiring` registers {@see BoundModelArgumentResolver} from
     * `DeferredComposition`, which `Kernel::boot()` drains after extension
     * register(), extension boot() and the project route files — so anything an
     * application or extension registers sits AHEAD of it, exactly as here.
     * Under first-claim-wins the application resolver took `$bound` and the
     * handler was invoked with an object no authorization hook had approved,
     * with a 200 and no trace anywhere that a substitution had happened.
     *
     * The status assertion is deliberate alongside the identity one: a fix that
     * merely broke the substitution would show up as a 500, and "the authorized
     * model reached the handler" is the property, not "the wrong one didn't".
     */
    #[Test]
    public function anApplicationResolverCannotSubstituteItsOwnValueForABoundModel(): void
    {
        $authorized = new CompositionEntity('authorized');
        $substitute = new CompositionEntity('substitute');

        $kernel = new Kernel();
        $controller = new CompositionController();
        $kernel->container()->instance(CompositionController::class, $controller);

        $provenance = new BindingProvenance();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);
        $pipeline->pipe(new CompositionBoundModelsMiddleware(['bound' => $authorized], $provenance));

        /** @var ArgumentResolverRegistryInterface $registry */
        $registry = $kernel->container()->get(ArgumentResolverRegistryInterface::class);
        $registry->add(new CompositionSubstitutingResolver($substitute));
        $registry->add(new BoundModelArgumentResolver($provenance));

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: '/e/{bound}',
            handler: [CompositionController::class, 'entityOnly'],
        ));

        $status = $kernel
            ->handle(new ServerRequest(method: 'GET', uri: 'http://localhost/e/authorized'))
            ->getStatusCode();

        self::assertSame(200, $status);
        self::assertSame([$authorized], $controller->arguments);
        self::assertNotSame($substitute, $controller->arguments[0]);
    }

    /**
     * Nothing in the three shapes above may turn on which resolver was added
     * first: the two never claim the same name. Where they DO contest a name,
     * the bound model wins from either side — because it is sealed, not because
     * of where it sits — which the test above proves through the kernel and
     * ArgumentResolverChainTest proves at the chain.
     */
    #[Test]
    public function bothRegistrationOrdersProduceTheSameArgumentList(): void
    {
        $entity = new CompositionEntity('e-1');
        $service = new InMemoryCompositionService();

        $boundFirst = $this->dispatch(
            method: 'serviceEntityRaw',
            pattern: '/d/{bound}/raw/{raw}',
            path: '/d/e-1/raw/xyz',
            models: ['bound' => $entity],
            service: $service,
            boundFirst: true,
        );

        $serviceFirst = $this->dispatch(
            method: 'serviceEntityRaw',
            pattern: '/d/{bound}/raw/{raw}',
            path: '/d/e-1/raw/xyz',
            models: ['bound' => $entity],
            service: $service,
            boundFirst: false,
        );

        self::assertSame($boundFirst->arguments, $serviceFirst->arguments);
        self::assertSame($boundFirst->status, $serviceFirst->status);
    }

    /**
     * @return array<string, bool>
     */
    private function bothRegistrationOrders(): array
    {
        return ['bound model registered first' => true, 'service registered first' => false];
    }

    /**
     * @param array<string, object> $models
     */
    private function dispatch(
        string $method,
        string $pattern,
        string $path,
        array $models,
        CompositionService $service,
        bool $boundFirst,
    ): CompositionController {
        $kernel = new Kernel();
        $controller = new CompositionController();
        $kernel->container()->instance(CompositionController::class, $controller);

        $provenance = new BindingProvenance();

        /** @var PostRoutingPipeline $pipeline */
        $pipeline = $kernel->container()->get(PostRoutingPipeline::class);
        $pipeline->pipe(new CompositionBoundModelsMiddleware($models, $provenance));

        /** @var ArgumentResolverRegistryInterface $registry */
        $registry = $kernel->container()->get(ArgumentResolverRegistryInterface::class);

        if ($boundFirst) {
            $registry->add(new BoundModelArgumentResolver($provenance));
            $registry->add(new CompositionServiceResolver($service));
        } else {
            $registry->add(new CompositionServiceResolver($service));
            $registry->add(new BoundModelArgumentResolver($provenance));
        }

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: $pattern,
            handler: [CompositionController::class, $method],
        ));

        $controller->status = $kernel
            ->handle(new ServerRequest(method: 'GET', uri: 'http://localhost' . $path))
            ->getStatusCode();

        return $controller;
    }
}

final class CompositionEntity
{
    public function __construct(public string $id) {}
}

/** The shape an injected collaborator normally takes: a type-hinted interface. */
interface CompositionService {}

final class InMemoryCompositionService implements CompositionService {}

/**
 * The trivial second resolver: supplies one service, by declared type, for any
 * parameter that names it. It knows nothing about routes or models.
 */
final readonly class CompositionServiceResolver implements HandlerArgumentResolverInterface
{
    public function __construct(private CompositionService $service) {}

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
        $claimed = [];

        foreach ($signature->parameters as $parameter) {
            if (!$parameter->builtin && $parameter->type !== null && is_a($this->service, $parameter->type)) {
                $claimed[$parameter->name] = $this->service;
            }
        }

        return $claimed;
    }
}

/**
 * An application resolver that claims a parameter a bound route also names.
 *
 * Not hostile by construction — this is what a resolver that fills entities from
 * its own store looks like, and it cannot see that the parameter it is claiming
 * carries a model an authorization hook approved. That is the point: the
 * substitution has to be impossible rather than discouraged.
 */
final readonly class CompositionSubstitutingResolver implements HandlerArgumentResolverInterface
{
    public function __construct(private CompositionEntity $substitute) {}

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
        $claimed = [];

        foreach ($signature->parameters as $parameter) {
            if ($parameter->type === CompositionEntity::class) {
                $claimed[$parameter->name] = $this->substitute;
            }
        }

        return $claimed;
    }
}

/** Stands in for ModelBindingMiddleware: attaches already-resolved models. */
final readonly class CompositionBoundModelsMiddleware implements MiddlewareInterface
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
        // The binder half of the stand-in: a model reaches a handler parameter
        // only if the framework recorded resolving it for that parameter and
        // that URL value, so the attribute alone would claim nothing.
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

final class CompositionController
{
    /** @var list<mixed> */
    public array $arguments = [];

    public int $status = 0;

    public function entityServiceRaw(
        CompositionEntity $bound,
        CompositionService $service,
        string $raw,
    ): ResponseInterface {
        $this->arguments = [$bound, $service, $raw];

        return Response::html('ok');
    }

    public function serviceEntityRaw(
        CompositionService $service,
        CompositionEntity $bound,
        string $raw,
    ): ResponseInterface {
        $this->arguments = [$service, $bound, $raw];

        return Response::html('ok');
    }

    public function entityServiceEntity(
        CompositionEntity $first,
        CompositionService $service,
        CompositionEntity $second,
    ): ResponseInterface {
        $this->arguments = [$first, $service, $second];

        return Response::html('ok');
    }

    public function entityOnly(CompositionEntity $bound): ResponseInterface
    {
        $this->arguments = [$bound];

        return Response::html('ok');
    }
}
