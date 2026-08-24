<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
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

use function array_map;
use function array_values;

#[CoversClass(BoundModelArgumentResolver::class)]
#[CoversClass(BindingProvenance::class)]
#[CoversClass(SealedArgument::class)]
final class BoundModelArgumentResolverTest extends TestCase
{
    /** The parameters the route matched, as the kernel hands them to the chain. */
    private const array ROUTE = ['user' => '1', 'post' => '20'];

    private ?MatchedRoute $dispatched = null;

    #[Test]
    public function claimsNothingWhenTheMiddlewareNeverRan(): void
    {
        $claimed = new BoundModelArgumentResolver(new BindingProvenance())->resolve(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            $this->request(),
            self::ROUTE,
        );

        self::assertSame([], $claimed);
    }

    #[Test]
    public function claimsNothingWhenNoModelsWereResolved(): void
    {
        $claimed = new BoundModelArgumentResolver(new BindingProvenance())->resolve(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            $this->request([]),
            self::ROUTE,
        );

        self::assertSame([], $claimed);
    }

    /**
     * The attribute is public request surface: any middleware can overwrite it
     * with anything. A non-array value must degrade to "no claim", never to a
     * type error inside the kernel's argument builder.
     */
    #[Test]
    public function claimsNothingWhenTheAttributeIsNotAnArray(): void
    {
        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/users/1')
            ->withAttribute('_bound_models', 'not-an-array');

        $claimed = new BoundModelArgumentResolver(new BindingProvenance())->resolve(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            $request,
            self::ROUTE,
        );

        self::assertSame([], $claimed);
    }

    #[Test]
    public function claimsAModelWhoseNameAndTypeBothMatch(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            ['user' => $user],
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsASubclassAgainstADeclaredParentType(): void
    {
        $user = new BoundAdminUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            ['user' => $user],
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsAnImplementationAgainstADeclaredInterfaceType(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', BoundIdentifiable::class, false, false, null)),
            ['user' => $user],
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    /**
     * `object` and `mixed` are builtin type names that accept every object
     * there is, and a resolver that read "builtin" as "accepts nothing" dropped
     * the authorized model on both. The kernel then filled the slot from the
     * route parameters, so a controller declaring `show(object $user)` — the
     * shape used when the entity class is generated, or when one handler serves
     * several — received the raw URL string in a parameter that would have
     * accepted the entity.
     */
    #[Test]
    public function claimsAModelForAParameterTypedObject(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', 'object', true, false, null, [['object']])),
            ['user' => $user],
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsAModelForAParameterTypedMixed(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', 'mixed', true, false, null, [['mixed']])),
            ['user' => $user],
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    /**
     * A route parameter name colliding with a scalar handler argument is the
     * ordinary case, not an error: the handler asked for the raw value and must
     * keep getting it.
     */
    #[Test]
    public function refusesToClaimABuiltinTypedParameter(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', 'string', true, false, null)),
            ['user' => $user],
        );

        self::assertSame([], $claimed);
    }

    /**
     * Null type means untyped, union or intersection. Nothing can be verified
     * against it, so nothing is claimed rather than gambling on a TypeError.
     */
    #[Test]
    public function refusesToClaimAnUntypedParameter(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', null, false, false, null)),
            ['user' => $user],
        );

        self::assertSame([], $claimed);
    }

    #[Test]
    public function refusesToClaimAModelOfAnUnrelatedType(): void
    {
        $model = new stdClass();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            ['user' => $model],
        );

        self::assertSame([], $claimed);
    }

    #[Test]
    public function ignoresBoundModelsWithNoMatchingParameter(): void
    {
        $post = new BoundPost();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('post', BoundPost::class, false, false, null)),
            ['user' => new BoundUser(), 'post' => $post],
        );

        self::assertSame(['post' => $post], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsEveryMatchingParameterOfAMultiSegmentRoute(): void
    {
        $user = new BoundUser();
        $post = new BoundPost();

        $claimed = $this->resolveAsFramework(
            $this->signature(
                new HandlerParameter('user', BoundUser::class, false, false, null),
                new HandlerParameter('post', BoundPost::class, false, false, null),
            ),
            ['user' => $user, 'post' => $post],
        );

        self::assertSame(['user' => $user, 'post' => $post], $this->unsealed($claimed));
    }

    /**
     * A parameter this resolver cannot supply is another resolver's business,
     * not a reason to stop. The route parameters are the only evidence this
     * class has, and they say nothing about what the rest of the chain claims —
     * so declining to claim a model because an unrelated parameter was declared
     * before it would make the model's arrival depend on a second resolver's
     * existence. Whether the argument list can actually be built is the
     * kernel's decision, taken once against every resolver's claims.
     */
    #[Test]
    public function claimsAModelDeclaredAfterAParameterItCannotSupplyItself(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(
                new HandlerParameter('service', BoundService::class, false, false, null),
                new HandlerParameter('user', BoundUser::class, false, false, null),
            ),
            ['user' => $user],
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsEveryModelAroundAParameterItCannotSupplyItself(): void
    {
        $user = new BoundUser();
        $post = new BoundPost();

        $claimed = $this->resolveAsFramework(
            $this->signature(
                new HandlerParameter('user', BoundUser::class, false, false, null),
                new HandlerParameter('service', BoundService::class, false, false, null),
                new HandlerParameter('post', BoundPost::class, false, false, null),
            ),
            ['user' => $user, 'post' => $post],
        );

        self::assertSame(['user' => $user, 'post' => $post], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsAcrossAParameterTheRouteCanFill(): void
    {
        $post = new BoundPost();

        $claimed = $this->resolveAsFramework(
            $this->signature(
                new HandlerParameter('tab', 'string', true, false, null),
                new HandlerParameter('post', BoundPost::class, false, false, null),
            ),
            ['post' => $post],
            ['tab' => 'settings', 'post' => '20'],
        );

        self::assertSame(['post' => $post], $this->unsealed($claimed));
    }

    #[Test]
    public function claimsAcrossAParameterThatCarriesADefault(): void
    {
        $post = new BoundPost();

        $claimed = $this->resolveAsFramework(
            $this->signature(
                new HandlerParameter('tab', 'string', true, true, 'overview'),
                new HandlerParameter('post', BoundPost::class, false, false, null),
            ),
            ['post' => $post],
            ['post' => '20'],
        );

        self::assertSame(['post' => $post], $this->unsealed($claimed));
    }

    /**
     * The seal, asserted on its own rather than only through the helper below.
     *
     * Every object this resolver hands over was loaded, tenant-scoped and passed
     * through the authorization hook by ModelBindingMiddleware. Sealing is what
     * stops a resolver registered anywhere else on the chain from substituting
     * its own object for it — and the framework's resolver is registered LAST by
     * construction, so nothing weaker than a seal would survive.
     */
    #[Test]
    public function everyClaimIsSealedAgainstDisplacement(): void
    {
        $user = new BoundUser();

        $claimed = $this->resolveAsFramework(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            ['user' => $user],
        );

        self::assertInstanceOf(SealedArgument::class, $claimed['user']);
        self::assertSame($user, $claimed['user']->value);
    }

    /**
     * THE FORGERY.
     *
     * `_bound_models` is an ordinary request attribute, so anything holding the
     * request can write it — a middleware inner to the binding one, an extension
     * frame, application code. Sealing on that evidence alone made the seal say
     * more than the middleware ever had: the object became undisplaceable on a
     * parameter typed as an entity, while nothing had established that any hook
     * approved it, or even that the framework produced it.
     *
     * A model with no provenance entry is therefore not sealed — and not
     * claimed at all, because handing the handler an unvetted object on that
     * parameter is the outcome the seal exists to prevent.
     */
    #[Test]
    public function refusesToSealAModelTheBindingPipelineNeverResolved(): void
    {
        $forged = new BoundUser();

        $claimed = new BoundModelArgumentResolver(new BindingProvenance())->resolve(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            $this->request(['user' => $forged]),
            self::ROUTE,
        );

        self::assertSame([], $claimed);
    }

    /**
     * Provenance is not a blanket "the framework made this object". An
     * attestation names the parameter it was minted for, so an object the binder
     * resolved for one level of a nested route cannot be presented as another.
     */
    #[Test]
    public function refusesToSealAModelAttestedForADifferentParameter(): void
    {
        $post = new BoundPost();

        $provenance = new BindingProvenance();
        $provenance->record($post, $this->dispatchedRoute(), 'other', self::ROUTE['post']);

        $claimed = new BoundModelArgumentResolver($provenance)->resolve(
            $this->signature(new HandlerParameter('post', BoundPost::class, false, false, null)),
            $this->request(['post' => $post]),
            self::ROUTE,
        );

        self::assertSame([], $claimed);
    }

    /**
     * The attestation names the URL value too, so a model the framework resolved
     * for one id cannot be served on a request that asks for another. That is
     * the replay this binding closes: an object minted for `{post}` = 20 in an
     * earlier request is worthless on `{post}` = 999.
     */
    #[Test]
    public function refusesToSealAModelAttestedForADifferentRouteValue(): void
    {
        $post = new BoundPost();

        $provenance = new BindingProvenance();
        $provenance->record($post, $this->dispatchedRoute(), 'post', '20');

        $claimed = new BoundModelArgumentResolver($provenance)->resolve(
            $this->signature(new HandlerParameter('post', BoundPost::class, false, false, null)),
            $this->request(['post' => $post]),
            ['post' => '999'],
        );

        self::assertSame([], $claimed);
    }

    /**
     * A parameter the route did not supply cannot have been bound from it, so
     * there is no value an attestation could be checked against. The claim is
     * declined rather than checked against something else.
     */
    #[Test]
    public function refusesToSealWhenTheRouteSuppliesNoValueForTheParameter(): void
    {
        $user = new BoundUser();

        $provenance = new BindingProvenance();
        $provenance->record($user, $this->dispatchedRoute(), 'user', '1');

        $claimed = new BoundModelArgumentResolver($provenance)->resolve(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            $this->request(['user' => $user]),
            [],
        );

        self::assertSame([], $claimed);
    }

    /**
     * Attestation is per instance, not per class: an equal-but-different object
     * substituted for the resolved one carries no entry of its own.
     */
    #[Test]
    public function refusesToSealASubstituteForAnAttestedModel(): void
    {
        $resolved = new BoundUser();
        $substitute = new BoundUser();

        $provenance = new BindingProvenance();
        $provenance->record($resolved, $this->dispatchedRoute(), 'user', self::ROUTE['user']);

        $claimed = new BoundModelArgumentResolver($provenance)->resolve(
            $this->signature(new HandlerParameter('user', BoundUser::class, false, false, null)),
            $this->request(['user' => $substitute]),
            self::ROUTE,
        );

        self::assertSame([], $claimed);
    }

    /**
     * One forged entry does not cost the request the models that are genuine.
     * The resolver judges each parameter on its own evidence, here as everywhere
     * else.
     */
    #[Test]
    public function refusesTheForgedParameterAndKeepsTheAttestedOne(): void
    {
        $user = new BoundUser();
        $forgedPost = new BoundPost();

        $provenance = new BindingProvenance();
        $provenance->record($user, $this->dispatchedRoute(), 'user', self::ROUTE['user']);

        $claimed = new BoundModelArgumentResolver($provenance)->resolve(
            $this->signature(
                new HandlerParameter('user', BoundUser::class, false, false, null),
                new HandlerParameter('post', BoundPost::class, false, false, null),
            ),
            $this->request(['user' => $user, 'post' => $forgedPost]),
            self::ROUTE,
        );

        self::assertSame(['user' => $user], $this->unsealed($claimed));
    }

    private function signature(HandlerParameter ...$parameters): HandlerSignature
    {
        return new HandlerSignature(BoundController::class, 'show', array_values($parameters));
    }

    /**
     * Resolve as the composed framework does: the binder resolved these models
     * for these route parameters — so each is attested — and the middleware
     * attached them to the request.
     *
     * @param array<string, object> $models
     * @param array<string, string>|null $routeParameters
     *
     * @return array<string, mixed>
     */
    private function resolveAsFramework(
        HandlerSignature $signature,
        array $models,
        ?array $routeParameters = null,
    ): array {
        $routeParameters ??= self::ROUTE;
        $provenance = new BindingProvenance();

        foreach ($models as $name => $model) {
            $routeValue = $routeParameters[$name] ?? null;

            if ($routeValue !== null) {
                $provenance->record($model, $this->dispatchedRoute(), $name, $routeValue);
            }
        }

        return new BoundModelArgumentResolver($provenance)->resolve(
            $signature,
            $this->request($models),
            $routeParameters,
        );
    }

    /**
     * Unwrap the claims so the expectations below stay about WHICH model reached
     * WHICH parameter, and assert on the way through that each one is sealed.
     *
     * Written as an assertion rather than a plain `->value` read on purpose: if
     * this resolver ever stopped sealing, every expectation in this file would
     * still describe the right models and none of them would notice that the
     * authorized object had become displaceable again.
     *
     * @param array<string, mixed> $claimed
     *
     * @return array<string, mixed>
     */
    private function unsealed(array $claimed): array
    {
        return array_map(
            static function (mixed $claim): mixed {
                self::assertInstanceOf(
                    SealedArgument::class,
                    $claim,
                    'Every bound-model claim must be sealed against displacement.',
                );

                return $claim->value;
            },
            $claimed,
        );
    }

    /**
     * @param array<string, object>|null $models
     */
    private function request(?array $models = null): ServerRequestInterface
    {
        // `_route` is the dispatched route the framework resolver checks an
        // attestation against; the kernel restores its own value onto the
        // request on the way into the handler frame, so a request built here
        // has to carry the same object the models were minted under.
        $request = new ServerRequest(method: 'GET', uri: 'http://localhost/users/1/posts/20')
            ->withAttribute('_route', $this->dispatchedRoute());

        return $models === null ? $request : $request->withAttribute('_bound_models', $models);
    }

    /**
     * One MatchedRoute per test, shared by the mint and the check, because an
     * attestation is bound to that object's identity.
     */
    private function dispatchedRoute(): MatchedRoute
    {
        return $this->dispatched ??= new MatchedRoute(
            new Route([Method::GET], '/users/{user}/posts/{post}', [BoundController::class, 'show'], 'bound.show'),
            self::ROUTE,
        );
    }
}

interface BoundIdentifiable {}

/** A type no bound model satisfies: stands in for another resolver's parameter. */
interface BoundService {}

class BoundUser implements BoundIdentifiable {}

final class BoundAdminUser extends BoundUser {}

final class BoundPost {}

final class BoundController
{
    public function show(): void {}
}
