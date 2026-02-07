<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

#[CoversClass(ModelBinder::class)]
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

        $result = $binder->bind($matched, $request, $context);

        self::assertArrayHasKey('user', $result);
        self::assertSame($model, $result['user']);
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

        (void) $binder->bind($matched, $request, new ResolutionContext());
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

        (void) $binder->bind($matched, $request, new ResolutionContext());
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

        $result = $binder->bind($matched, $request, new ResolutionContext());

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
                    scoped: true,
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

        $result = $binder->bind($matched, $request, new ResolutionContext());

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

        (void) $binder->bind($matched, $request, new ResolutionContext());
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

        $result = $binder->bind($matched, $request, new ResolutionContext());

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

        $result = $binder->bind($matched, $request, new ResolutionContext());

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

        $result = $binder->bind($matched, $request, new ResolutionContext());

        self::assertSame([], $result);
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
