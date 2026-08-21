<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Controller;

use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Core\Controller\ArgumentResolverChain;
use Pulsar\Core\Controller\ArgumentResolverLifecycle;
use Pulsar\Core\Controller\ArgumentResolverLifecycleException;
use Pulsar\Core\Controller\ConflictingSealedArgumentException;
use Pulsar\Core\Controller\HandlerArgumentResolverInterface;
use Pulsar\Core\Controller\HandlerParameter;
use Pulsar\Core\Controller\HandlerSignature;
use Pulsar\Core\Controller\SealedArgument;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingProvenance;
use Pulsar\Routing\Binding\BoundModelArgumentResolver;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

use function array_key_exists;

#[CoversClass(ArgumentResolverChain::class)]
#[CoversClass(SealedArgument::class)]
#[CoversClass(ConflictingSealedArgumentException::class)]
#[CoversClass(BoundModelArgumentResolver::class)]
#[CoversClass(BindingProvenance::class)]
#[CoversClass(ArgumentResolverLifecycle::class)]
#[CoversClass(ArgumentResolverLifecycleException::class)]
#[CoversClass(HandlerSignature::class)]
#[CoversClass(HandlerParameter::class)]
final class ArgumentResolverChainTest extends TestCase
{
    /** The parameters the route matched, as the kernel hands them to the chain. */
    private const array ROUTE_PARAMETERS = ['user' => '1', 'post' => '2'];

    private ?MatchedRoute $dispatched = null;

    #[Test]
    public function emptyChainClaimsNothing(): void
    {
        $chain = new ArgumentResolverChain();

        self::assertSame([], $chain->resolvers);
        self::assertSame([], $chain->resolveArguments($this->signature(), $this->request(), []));
    }

    #[Test]
    public function mergesClaimsFromEveryResolver(): void
    {
        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => 'from-first']));
        $chain->add(new StubArgumentResolver(['post' => 'from-second']));

        self::assertSame(
            ['user' => 'from-first', 'post' => 'from-second'],
            $chain->resolveArguments($this->signature(), $this->request(), []),
        );
    }

    /**
     * Ordinary claims stay first-claim-wins. That is the right rule for two
     * interchangeable values — a service, a decoded body — where neither
     * candidate outranks the other and picking one is a composition preference.
     * It is NOT the rule that protects a security-relevant value; sealing is,
     * and the tests below exercise it.
     */
    #[Test]
    public function firstOrdinaryClaimWinsOnAContestedName(): void
    {
        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => 'first']));
        $chain->add(new StubArgumentResolver(['user' => 'second']));

        self::assertSame(
            ['user' => 'first'],
            $chain->resolveArguments($this->signature(), $this->request(), []),
        );
    }

    /**
     * A key present with a null value is a claim of null, distinct from no
     * claim at all: a later resolver must not be able to overwrite it.
     */
    #[Test]
    public function aNullClaimIsAClaimAndBlocksLaterResolvers(): void
    {
        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => null]));
        $chain->add(new StubArgumentResolver(['user' => 'late']));

        $claimed = $chain->resolveArguments($this->signature(), $this->request(), []);

        self::assertTrue(array_key_exists('user', $claimed));
        self::assertNull($claimed['user']);
    }

    /**
     * THE DISPLACEMENT ATTEMPT, against the real framework resolver.
     *
     * This is the shape that reaches production: `ModelBindingWiring` registers
     * {@see BoundModelArgumentResolver} from `DeferredComposition`, which the
     * kernel drains at the very END of boot — after extension register(), after
     * extension boot(), after the project route files. Anything an application
     * or extension registers is therefore AHEAD of it on the chain. Under plain
     * first-claim-wins the application resolver took the name and the handler
     * received an object no authorization hook had ever seen, on the parameter
     * whose entity type hint is what makes the route look safe.
     *
     * The assertion is identity, not shape: an equal-but-different object is
     * exactly the failure being excluded.
     */
    #[Test]
    public function anApplicationResolverCannotDisplaceAnAuthorizedBoundModel(): void
    {
        $authorized = new stdClass();
        $substitute = new stdClass();

        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => $substitute]));
        $chain->add($this->boundModelResolver(['user' => $authorized]));

        $claimed = $chain->resolveArguments(
            $this->signature(),
            $this->requestWithBoundModels(['user' => $authorized]),
            self::ROUTE_PARAMETERS,
        );

        self::assertSame($authorized, $claimed['user']);
        self::assertNotSame($substitute, $claimed['user']);
    }

    /**
     * The same attempt with the application resolver registered LAST, which is
     * the order the old contract told integrators to arrange for. Both orders
     * must produce the authorized model: if only this one did, the property
     * would still be "get the wiring right", which is the burden being removed.
     */
    #[Test]
    public function anApplicationResolverRegisteredAfterTheFrameworkAlsoFailsToDisplace(): void
    {
        $authorized = new stdClass();
        $substitute = new stdClass();

        $chain = new ArgumentResolverChain();
        $chain->add($this->boundModelResolver(['user' => $authorized]));
        $chain->add(new StubArgumentResolver(['user' => $substitute]));

        $claimed = $chain->resolveArguments(
            $this->signature(),
            $this->requestWithBoundModels(['user' => $authorized]),
            self::ROUTE_PARAMETERS,
        );

        self::assertSame($authorized, $claimed['user']);
    }

    /**
     * Displacement fails on the contested name only. A resolver that loses
     * `user` must still supply every other parameter it claims, or the fix
     * would have turned a security bug into a broken composition.
     */
    #[Test]
    public function losingASealedNameCostsTheResolverNoneOfItsOtherClaims(): void
    {
        $authorized = new stdClass();
        $service = new stdClass();

        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => new stdClass(), 'post' => $service]));
        $chain->add($this->boundModelResolver(['user' => $authorized]));

        $claimed = $chain->resolveArguments(
            $this->signature(),
            $this->requestWithBoundModels(['user' => $authorized]),
            self::ROUTE_PARAMETERS,
        );

        self::assertSame($authorized, $claimed['user']);
        self::assertSame($service, $claimed['post']);
    }

    /**
     * The mechanism itself, without the framework resolver: a sealed claim wins
     * from either side of an ordinary one. Registration order is what the
     * framework cannot control, so it must not be what decides.
     */
    #[Test]
    public function aSealedClaimWinsFromEitherSideOfAnOrdinaryClaim(): void
    {
        $sealedFirst = new ArgumentResolverChain();
        $sealedFirst->add(new StubArgumentResolver(['user' => new SealedArgument('sealed')]));
        $sealedFirst->add(new StubArgumentResolver(['user' => 'ordinary']));

        $sealedLast = new ArgumentResolverChain();
        $sealedLast->add(new StubArgumentResolver(['user' => 'ordinary']));
        $sealedLast->add(new StubArgumentResolver(['user' => new SealedArgument('sealed')]));

        self::assertSame(
            ['user' => 'sealed'],
            $sealedFirst->resolveArguments($this->signature(), $this->request(), []),
        );
        self::assertSame(
            ['user' => 'sealed'],
            $sealedLast->resolveArguments($this->signature(), $this->request(), []),
        );
    }

    /**
     * The handler declared a domain type, so it must receive the value and never
     * the wrapper. One layer is unwrapped, at one place, by the chain.
     */
    #[Test]
    public function aSealedClaimIsDeliveredUnwrapped(): void
    {
        $model = new stdClass();

        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => new SealedArgument($model)]));

        $claimed = $chain->resolveArguments($this->signature(), $this->request(), []);

        self::assertSame($model, $claimed['user']);
    }

    /**
     * A sealed null is a claim of null that nothing may overwrite — the same
     * present-key/absent-key distinction the ordinary merge draws, carried
     * through the seal rather than lost at it.
     */
    #[Test]
    public function aSealedNullIsAClaimAndSurvivesAnOrdinaryClaimMadeFirst(): void
    {
        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => 'ordinary']));
        $chain->add(new StubArgumentResolver(['user' => new SealedArgument(null)]));

        $claimed = $chain->resolveArguments($this->signature(), $this->request(), []);

        self::assertTrue(array_key_exists('user', $claimed));
        self::assertNull($claimed['user']);
    }

    /**
     * Two seals on one name have no honest resolution: both resolvers assert
     * final authority, and preferring the earlier registration would put order
     * back exactly where sealing removed it. The chain refuses and the request
     * fails closed rather than serving one of the two values.
     */
    #[Test]
    public function twoSealsOnOneParameterAreRefusedRatherThanRanked(): void
    {
        $authorized = new stdClass();

        $chain = new ArgumentResolverChain();
        $chain->add($this->boundModelResolver(['user' => $authorized]));
        $chain->add(new StubArgumentResolver(['user' => new SealedArgument(new stdClass())]));

        try {
            (void) $chain->resolveArguments(
                $this->signature(),
                $this->requestWithBoundModels(['user' => $authorized]),
                self::ROUTE_PARAMETERS,
            );
            self::fail('A second seal on an already-sealed parameter must be refused.');
        } catch (ConflictingSealedArgumentException $e) {
            self::assertStringContainsString('$user', $e->getMessage());
        }
    }

    /**
     * The refusal names the parameter, the second resolver and the handler,
     * because the fix is a composition change and the operator has to know which
     * registration to look at.
     */
    #[Test]
    public function theConflictNamesTheParameterTheSecondResolverAndTheHandler(): void
    {
        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => new SealedArgument('first')]));
        $chain->add(new StubArgumentResolver(['user' => new SealedArgument('second')]));

        try {
            (void) $chain->resolveArguments($this->signature(), $this->request(), []);
            self::fail('Two seals on one parameter must be refused.');
        } catch (ConflictingSealedArgumentException $e) {
            self::assertStringContainsString('$user', $e->getMessage());
            self::assertStringContainsString(StubArgumentResolver::class, $e->getMessage());
            self::assertStringContainsString(stdClass::class . '::show', $e->getMessage());
        }
    }

    /**
     * A seal on one parameter says nothing about any other: a second seal is a
     * conflict only where the NAME collides.
     */
    #[Test]
    public function twoResolversMaySealDifferentParameters(): void
    {
        $chain = new ArgumentResolverChain();
        $chain->add(new StubArgumentResolver(['user' => new SealedArgument('u')]));
        $chain->add(new StubArgumentResolver(['post' => new SealedArgument('p')]));

        self::assertSame(
            ['user' => 'u', 'post' => 'p'],
            $chain->resolveArguments($this->signature(), $this->request(), []),
        );
    }

    /**
     * Wrapping a seal in a seal is a construction mistake, not a stronger seal.
     * The chain unwraps one layer, so letting it through would hand the handler
     * a wrapper where it declared a domain type and the TypeError would name
     * neither the resolver nor the parameter.
     */
    #[Test]
    public function aSealedArgumentRefusesToWrapAnotherSealedArgument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SealedArgument(new SealedArgument('inner'));
    }

    #[Test]
    public function resolversAreConsultedInRegistrationOrder(): void
    {
        $chain = new ArgumentResolverChain();
        $first = new StubArgumentResolver([]);
        $second = new StubArgumentResolver([]);
        $chain->add($first);
        $chain->add($second);

        self::assertSame([$first, $second], $chain->resolvers);
    }

    #[Test]
    public function resolversReceiveTheSignatureRequestAndRouteParameters(): void
    {
        $signature = $this->signature();
        $request = $this->request();
        $resolver = new StubArgumentResolver([]);

        $chain = new ArgumentResolverChain();
        $chain->add($resolver);
        (void) $chain->resolveArguments($signature, $request, ['user' => '42']);

        self::assertSame($signature, $resolver->seenSignature);
        self::assertSame($request, $resolver->seenRequest);
        self::assertSame(['user' => '42'], $resolver->seenRouteParameters);
    }

    /**
     * The chain is populated during boot exactly like the router and the global
     * middleware pipeline, so a worker that recycles must be able to restore the
     * pre-boot baseline. Without it, a re-boot stacks a second copy of every
     * resolver and each bound model is resolved twice per request.
     */
    #[Test]
    public function snapshotAndRestoreRoundTripTheChain(): void
    {
        $chain = new ArgumentResolverChain();
        $lifecycle = $chain->issueLifecycle();
        $baseline = $lifecycle->snapshot();

        $chain->add(new StubArgumentResolver(['user' => 'boot']));
        self::assertCount(1, $chain->resolvers);

        $lifecycle->restore($baseline);

        self::assertSame([], $chain->resolvers);
    }

    #[Test]
    public function restoreDropsOnlyResolversAddedAfterTheSnapshot(): void
    {
        $chain = new ArgumentResolverChain();
        $lifecycle = $chain->issueLifecycle();

        $preBoot = new StubArgumentResolver(['user' => 'pre-boot']);
        $chain->add($preBoot);

        $baseline = $lifecycle->snapshot();
        $chain->add(new StubArgumentResolver(['post' => 'boot']));
        $lifecycle->restore($baseline);

        self::assertSame([$preBoot], $chain->resolvers);
    }

    /**
     * THE REPLACEMENT ATTEMPT.
     *
     * Restoring the chain is the power to un-register a resolver, which is the
     * power to remove a seal — a resolver that is no longer on the chain makes
     * no claim for anything to protect. It used to be an unguarded public method
     * on an object the kernel publishes in the container, so anything that could
     * reach the container could drop the bound-model resolver and then take its
     * parameter with an ordinary claim.
     *
     * The kernel takes the one handle in its constructor. Every later caller —
     * which is every caller, since nothing else runs that early — is refused.
     */
    #[Test]
    public function theLifecycleHandleIsIssuedOnceAndLaterRequestsAreRefused(): void
    {
        $chain = new ArgumentResolverChain();
        $kernelHandle = $chain->issueLifecycle();

        $baseline = $kernelHandle->snapshot();
        $registered = new StubArgumentResolver(['user' => 'from-boot']);
        $chain->add($registered);

        try {
            (void) $chain->issueLifecycle();
            self::fail('A second lifecycle handle must be refused.');
        } catch (ArgumentResolverLifecycleException $e) {
            self::assertStringContainsString('already issued', $e->getMessage());
        }

        // The refusal changed nothing: the chain still holds what boot put on
        // it, and the kernel's own handle still works.
        self::assertSame([$registered], $chain->resolvers);

        $kernelHandle->restore($baseline);
        self::assertSame([], $chain->resolvers);
    }

    /**
     * A lifecycle built by hand reaches whatever its own closures reach, which
     * is not the kernel's chain. The constructor is public precisely because the
     * closures, not the type, are the capability.
     */
    #[Test]
    public function aLifecycleBuiltByHandCannotReachTheKernelChain(): void
    {
        $chain = new ArgumentResolverChain();
        (void) $chain->issueLifecycle();

        $registered = new StubArgumentResolver(['user' => 'from-boot']);
        $chain->add($registered);

        $decoy = [];
        $forged = new ArgumentResolverLifecycle(
            capture: static fn(): array => $decoy,
            apply: static function (array $snapshot) use (&$decoy): void {
                $decoy = $snapshot;
            },
        );

        $forged->restore([new StubArgumentResolver(['user' => 'substituted'])]);

        self::assertSame([$registered], $chain->resolvers);
    }

    private function signature(): HandlerSignature
    {
        return new HandlerSignature(stdClass::class, 'show', [
            new HandlerParameter('user', stdClass::class, false, false, null),
            new HandlerParameter('post', stdClass::class, false, false, null),
        ]);
    }

    private function request(): ServerRequestInterface
    {
        // `_route` carries the dispatched route, which is what the framework
        // resolver checks an attestation against. The kernel restores its own
        // value here on the way into the handler frame, so a request built for
        // this resolver has to carry the same route the models were minted
        // under or nothing is claimed.
        return new ServerRequest(method: 'GET', uri: 'http://localhost/users/1/posts/2')
            ->withAttribute('_route', $this->dispatchedRoute());
    }

    /**
     * One MatchedRoute per test, shared by the mint and the check, because the
     * attestation is bound to that object's identity.
     */
    private function dispatchedRoute(): MatchedRoute
    {
        return $this->dispatched ??= new MatchedRoute(
            new Route([Method::GET], '/users/{user}/posts/{post}', ChainProbeController::class, 'chain.show'),
            self::ROUTE_PARAMETERS,
        );
    }

    /**
     * The request as ModelBindingMiddleware leaves it: the models it resolved,
     * tenant-scoped and passed through the authorization hook, under the
     * attribute BoundModelArgumentResolver reads.
     *
     * @param array<string, object> $models
     */
    private function requestWithBoundModels(array $models): ServerRequestInterface
    {
        return $this->request()->withAttribute('_bound_models', $models);
    }

    /**
     * The framework resolver as ModelBindingWiring composes it: holding the
     * provenance record its binder writes, with each model minted for the
     * parameter and URL value this route matched.
     *
     * The record is half of what a seal now rests on, so a test that skipped it
     * would be exercising a resolver that claims nothing.
     *
     * @param array<string, object> $models
     */
    private function boundModelResolver(array $models): BoundModelArgumentResolver
    {
        $provenance = new BindingProvenance();

        foreach ($models as $name => $model) {
            $provenance->record($model, $this->dispatchedRoute(), $name, self::ROUTE_PARAMETERS[$name]);
        }

        return new BoundModelArgumentResolver($provenance);
    }
}

final class StubArgumentResolver implements HandlerArgumentResolverInterface
{
    public ?HandlerSignature $seenSignature = null;

    public ?ServerRequestInterface $seenRequest = null;

    /** @var array<string, string>|null */
    public ?array $seenRouteParameters = null;

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
        $this->seenSignature = $signature;
        $this->seenRequest = $request;
        $this->seenRouteParameters = $routeParameters;

        return $this->claims;
    }
}

/** A handler the probe route can name. Never invoked. */
final class ChainProbeController
{
    public function __invoke(): void {}
}
