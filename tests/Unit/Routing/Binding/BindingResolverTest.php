<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

#[CoversClass(BindingResolver::class)]
final class BindingResolverTest extends TestCase
{
    #[Test]
    public function compiledMapFastPathUsesCompiledBindings(): void
    {
        $compiledMeta = new BindingMeta(class: stdClass::class, keyName: 'uuid', keyType: 'string');
        $compiledMap = new CompiledBindingMap([
            'users.show' => ['user' => $compiledMeta],
        ]);

        $resolver = new BindingResolver(compiledMap: $compiledMap);

        $route = new Route([Method::GET], '/users/{user}', [BindingResolverTestController::class, 'show'], 'users.show');
        $matched = new MatchedRoute($route, ['user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertArrayHasKey('user', $result);
        self::assertSame('uuid', $result['user']->keyName);
        self::assertSame('string', $result['user']->keyType);
    }

    #[Test]
    public function reflectionFallbackResolvesFromTypeHints(): void
    {
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertArrayHasKey('user', $result);
        self::assertSame(stdClass::class, $result['user']->class);
        self::assertSame('id', $result['user']->keyName);
    }

    #[Test]
    public function explicitOverridesReplaceImplicitResolution(): void
    {
        $explicit = new ExplicitBinding(
            parameter: 'user',
            modelClass: stdClass::class,
            resolverClass: stdClass::class,
        );

        $resolver = new BindingResolver(explicitBindings: [$explicit]);

        $route = new Route([Method::GET], '/users/{user}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertArrayHasKey('user', $result);
        self::assertSame(stdClass::class, $result['user']->class);
        self::assertSame(stdClass::class, $result['user']->customResolver);
    }

    #[Test]
    public function customKeyParsing(): void
    {
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user:slug}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => 'john-doe']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertArrayHasKey('user', $result);
        self::assertSame('slug', $result['user']->keyName);
        self::assertSame('string', $result['user']->keyType);
    }

    #[Test]
    public function nonClassTypeHintsAreSkipped(): void
    {
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{id}', [BindingResolverTestController::class, 'showById']);
        $matched = new MatchedRoute($route, ['id' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showById');

        self::assertArrayNotHasKey('id', $result);
    }

    #[Test]
    public function parametersNotInRouteAreSkipped(): void
    {
        $resolver = new BindingResolver();

        // Route only has {user}, but controller also type-hints a $post that is not in route
        $route = new Route([Method::GET], '/users/{user}', [BindingResolverTestController::class, 'showWithExtra']);
        $matched = new MatchedRoute($route, ['user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showWithExtra');

        self::assertArrayHasKey('user', $result);
        self::assertArrayNotHasKey('post', $result);
    }

    #[Test]
    public function unnamedRouteSkipsCompiledMapAndUsesReflection(): void
    {
        $compiledMap = new CompiledBindingMap([
            'users.show' => [
                'user' => new BindingMeta(class: stdClass::class, keyName: 'compiled_key'),
            ],
        ]);

        $resolver = new BindingResolver(compiledMap: $compiledMap);

        // Unnamed route — compiled map should be skipped
        $route = new Route([Method::GET], '/users/{user}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        // Should use reflection fallback, not compiled "compiled_key"
        self::assertSame('id', $result['user']->keyName);
    }

    #[Test]
    public function explicitBindingDoesNotApplyWhenParameterNotInRoute(): void
    {
        $explicit = new ExplicitBinding(
            parameter: 'post',
            modelClass: stdClass::class,
        );

        $resolver = new BindingResolver(explicitBindings: [$explicit]);

        $route = new Route([Method::GET], '/users/{user}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertArrayNotHasKey('post', $result);
    }
}

/**
 * Dummy controller for reflection-based binding resolution tests.
 */
final class BindingResolverTestController
{
    public function show(stdClass $user): void {}

    public function showById(int $id): void {}

    public function showWithExtra(stdClass $user, stdClass $post): void {}
}
