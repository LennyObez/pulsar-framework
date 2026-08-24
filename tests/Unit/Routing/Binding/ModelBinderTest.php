<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingAuthorization;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingProvenance;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;

#[CoversClass(ModelBinder::class)]
#[CoversClass(BindingAuthorization::class)]
final class ModelBinderTest extends TestCase
{
    #[Test]
    public function implicitBindingResolvesFromTypeHints(): void
    {
        $model = new stdClass();
        $model->name = 'John';

        $defaultResolver = $this->createMock(ModelResolverPort::class);
        $defaultResolver->expects(self::once())
            ->method('resolve')
            ->with(stdClass::class, 'id', self::anything(), self::isInstanceOf(ResolutionContext::class))
            ->willReturn($model);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createStub(ServerRequestInterface::class);
        $context = new ResolutionContext();

        $result = $binder->bind($matched, $request, $context, self::noPolicy($matched));

        self::assertArrayHasKey('user', $result);
        self::assertSame($model, $result['user']);
    }

    #[Test]
    public function bindWithMetaReturnsResolvedModelsAndTheirMetadata(): void
    {
        $model = new stdClass();

        $defaultResolver = $this->createStub(ModelResolverPort::class);
        $defaultResolver->method('resolve')->willReturn($model);

        $meta = new BindingMeta(
            class: stdClass::class,
            keyName: 'id',
            keyType: 'string',
            authzPolicy: 'delete',
        );
        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => ['user' => $meta],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createStub(ServerRequestInterface::class);
        $context = new ResolutionContext();

        $resolved = $binder->bindWithMeta($matched, $request, $context, self::noPolicy($matched));

        // Models and the metadata that produced them are returned together,
        // keyed by parameter name, so authorization can read the declared policy.
        self::assertSame(['user' => $model], $resolved->models);
        self::assertArrayHasKey('user', $resolved->metas);
        self::assertSame('delete', $resolved->metas['user']->authzPolicy);

        // bind() stays a thin BC delegate returning only the models.
        self::assertSame(['user' => $model], $binder->bind($matched, $request, $context, self::noPolicy($matched)));
    }

    #[Test]
    public function strictKeyCoercionThrowsForNonIntegerValue(): void
    {
        $defaultResolver = $this->createStub(ModelResolverPort::class);
        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'int'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => 'abc']);
        $request = $this->createStub(ServerRequestInterface::class);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        (void) $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));
    }

    #[Test]
    public function strictKeyCoercionThrowsForNegativeInteger(): void
    {
        $defaultResolver = $this->createStub(ModelResolverPort::class);
        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'int'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '-1']);
        $request = $this->createStub(ServerRequestInterface::class);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        (void) $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));
    }

    #[Test]
    public function strictKeyCoercionPassesForValidIntegerString(): void
    {
        $model = new stdClass();

        $defaultResolver = $this->createMock(ModelResolverPort::class);
        $defaultResolver->expects(self::once())
            ->method('resolve')
            ->with(stdClass::class, 'id', 42, self::isInstanceOf(ResolutionContext::class))
            ->willReturn($model);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'int'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createStub(ServerRequestInterface::class);

        $result = $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));

        self::assertSame($model, $result['user']);
    }

    #[Test]
    public function scopedBindingUsesResolveScopedWithParent(): void
    {
        $parent = new stdClass();
        $parent->name = 'User';
        $child = new stdClass();
        $child->name = 'Post';

        $defaultResolver = $this->createMock(ModelResolverPort::class);

        $defaultResolver->expects(self::once())
            ->method('resolve')
            ->willReturn($parent);

        $defaultResolver->expects(self::once())
            ->method('resolveScoped')
            ->with(
                stdClass::class,
                'id',
                self::anything(),
                $parent,
                'posts',
                self::isInstanceOf(ResolutionContext::class),
            )
            ->willReturn($child);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    scope: BindingScope::Contained,
                    parentRelation: 'posts',
                ),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [ModelBinderTestScopedController::class, 'show'],
            'posts.show',
        );
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '5']);
        $request = $this->createStub(ServerRequestInterface::class);

        $result = $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));

        self::assertArrayHasKey('user', $result);
        self::assertArrayHasKey('post', $result);
        self::assertSame($parent, $result['user']);
        self::assertSame($child, $result['post']);
    }

    #[Test]
    public function modelNotFoundThrowsException(): void
    {
        $defaultResolver = $this->createStub(ModelResolverPort::class);
        $defaultResolver->method('resolve')->willReturn(null);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '999']);
        $request = $this->createStub(ServerRequestInterface::class);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        (void) $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));
    }

    #[Test]
    public function customResolverIsUsedWhenConfigured(): void
    {
        $model = new stdClass();
        $model->name = 'Custom';

        $customResolver = $this->createMock(ModelResolverPort::class);
        $customResolver->expects(self::once())
            ->method('resolve')
            ->willReturn($model);

        $defaultResolver = $this->createStub(ModelResolverPort::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())
            ->method('get')
            ->with(stdClass::class)
            ->willReturn($customResolver);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(
                    class: stdClass::class,
                    keyName: 'id',
                    keyType: 'string',
                    customResolver: stdClass::class,
                ),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $container);

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createStub(ServerRequestInterface::class);

        $result = $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));

        self::assertSame($model, $result['user']);
    }

    #[Test]
    public function closureHandlerReturnsEmptyArray(): void
    {
        $defaultResolver = $this->createStub(ModelResolverPort::class);
        $bindingResolver = new BindingResolver();

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', static fn() => null, 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);
        $request = $this->createStub(ServerRequestInterface::class);

        $result = $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));

        self::assertSame([], $result);
    }

    #[Test]
    public function parameterNotInRouteIsSkipped(): void
    {
        $defaultResolver = $this->createStub(ModelResolverPort::class);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        // Empty parameters — route param 'user' has no value
        $matched = new MatchedRoute($route, []);
        $request = $this->createStub(ServerRequestInterface::class);

        $result = $binder->bind($matched, $request, new ResolutionContext(), self::noPolicy($matched));

        self::assertSame([], $result);
    }

    // ── Binding cannot happen without an authorization decision ──────────

    /**
     * The authorization is a required argument, on both entry points.
     *
     * Asserted by reflection because the property is a property of the
     * SIGNATURE, and the only runtime evidence of "this cannot be omitted" is
     * that PHP would refuse the call. A default value here — the
     * `?Closure $authorize = null` this used to carry — is what let a model be
     * resolved, minted into {@see BindingProvenance} and sealed onto a handler
     * parameter without anything having decided the caller may have it. Nullable
     * would be the same hole spelled differently.
     *
     * @param 'bind'|'bindWithMeta' $method
     */
    #[Test]
    #[DataProvider('bindingEntryPointProvider')]
    public function neitherEntryPointCanBeCalledWithoutAnAuthorizationDecision(string $method): void
    {
        $parameters = new ReflectionMethod(ModelBinder::class, $method)->getParameters();
        $authorization = $parameters[3] ?? null;

        self::assertNotNull($authorization, $method . '() must take an authorization decision');
        self::assertSame('authorization', $authorization->getName());
        self::assertFalse($authorization->isOptional(), 'an omitted authorization must not be a legal call');

        $type = $authorization->getType();

        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(BindingAuthorization::class, $type->getName());
        self::assertFalse($type->allowsNull(), 'null must not be a way to say "nothing decided this"');
    }

    /**
     * @return iterable<string, array{'bind'|'bindWithMeta'}>
     */
    public static function bindingEntryPointProvider(): iterable
    {
        yield 'bind()' => ['bind'];
        yield 'bindWithMeta()' => ['bindWithMeta'];
    }

    #[Test]
    public function aRegulatedPresetCannotBeExemptedFromAuthorization(): void
    {
        // The exemption verifies its own precondition rather than trusting the
        // caller: under a preset that mandates authorization there is no value
        // of the parameter that means "nobody decided", so the only decision
        // constructible for such a route is a gate.
        $matched = new MatchedRoute(
            new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show'),
            ['user' => '42'],
        );

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        (void) BindingAuthorization::unenforcedPreset($matched, BindingPreset::Banking);
    }

    #[Test]
    public function anOptOutExemptionRequiresTheRouteToDeclareTheOptOut(): void
    {
        // The other exemption rests on a route attribute, which comes from the
        // route table and cannot be introduced by a request. Claiming it for a
        // route that never declared it is an authorization bypass wearing the
        // shape of a legal exemption, so it is refused where it is claimed.
        $matched = new MatchedRoute(
            new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show'),
            ['user' => '42'],
        );

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        (void) BindingAuthorization::declaredWithoutAuthorization($matched);
    }

    #[Test]
    public function anAuthorizationDecidedForAnotherRouteBindsNothing(): void
    {
        // An exemption obtained legally for a public route must not be a value
        // that binds any route at all. The binder compares the decision's route
        // with the match it was handed, and refuses before a resolver is called.
        $defaultResolver = $this->createMock(ModelResolverPort::class);
        $defaultResolver->expects(self::never())->method('resolve');

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $binder = new ModelBinder($defaultResolver, $bindingResolver, $this->createStub(ContainerInterface::class));

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);
        $otherRoute = new MatchedRoute(
            new Route(
                [Method::GET],
                '/public/{user}',
                [ModelBinderTestController::class, 'show'],
                'public.show',
                ['_without_authorization' => true],
            ),
            ['user' => '42'],
        );

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        (void) $binder->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            BindingAuthorization::declaredWithoutAuthorization($otherRoute),
        );
    }

    #[Test]
    public function aModelTheDecisionRefusesIsNeverMinted(): void
    {
        // Provenance is the evidence the argument resolver seals on, so a model
        // that failed the gate must leave no entry: the throw is the visible
        // half of the refusal, and this is the half that decides whether the
        // object could still reach a handler through `_bound_models`.
        $model = new stdClass();

        $defaultResolver = $this->createStub(ModelResolverPort::class);
        $defaultResolver->method('resolve')->willReturn($model);

        $bindingResolver = $this->createBindingResolverWithCompiledMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'id', keyType: 'string'),
            ],
        ]);

        $provenance = new BindingProvenance();
        $binder = new ModelBinder(
            $defaultResolver,
            $bindingResolver,
            $this->createStub(ContainerInterface::class),
            $provenance,
        );

        $route = new Route([Method::GET], '/users/{user}', [ModelBinderTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);

        try {
            (void) $binder->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                BindingAuthorization::gate($matched, static fn(): bool => false),
            );
            self::fail('A refused level must stop the binding.');
        } catch (ModelBindingException $refusal) {
            // The refused caller learns exactly what a caller asking for a row
            // that is not there learns.
            self::assertSame(404, $refusal->getCode());
        }

        self::assertFalse(
            $provenance->attests($model, $matched, 'user', '42'),
            'a model the gate refused must not carry an attestation',
        );
    }

    /**
     * The decision a test that is not about authorization binds under.
     *
     * Standard is the only preset an exemption can be constructed for, so every
     * one of these cases says out loud that it resolves models under a posture
     * where nothing authorizes them — which is what leaves them free to be about
     * key coercion, scoping and handler shapes instead.
     */
    private static function noPolicy(MatchedRoute $matched): BindingAuthorization
    {
        return BindingAuthorization::unenforcedPreset($matched, BindingPreset::Standard);
    }

    /**
     * Create a real BindingResolver backed by a CompiledBindingMap.
     *
     * @param array<string, array<string, BindingMeta>> $map
     */
    private function createBindingResolverWithCompiledMap(array $map): BindingResolver
    {
        return new BindingResolver(compiledMap: new CompiledBindingMap($map));
    }
}

/**
 * Dummy controller for handler info extraction tests.
 */
final class ModelBinderTestController
{
    public function show(stdClass $user): void {}
}

final class ModelBinderTestScopedController
{
    public function show(stdClass $user, stdClass $post): void {}
}
