<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;
use stdClass;

use function array_keys;

/**
 * Binding metadata is decided once per route shape, not once per request.
 *
 * Every input {@see BindingResolver::resolveForRoute()} reads is fixed when the
 * route table is built: the handler's signature, the route path, the route
 * name, the parameter names the path declares, and the resolver's own explicit
 * bindings and compiled map. None of them can change between two requests for
 * the same route, so deciding the answer twice is work with no possible second
 * outcome — and it was being paid on every request, through a fresh
 * `ReflectionMethod` and a fresh parse of the path.
 *
 * These tests pin both halves of that: the answer is reused, and the key that
 * decides "the same route shape" is narrow enough that no two shapes can be
 * served each other's metadata. The second half is the security-relevant one —
 * a memo keyed too loosely would hand a route a containment decision taken for
 * a different path.
 */
#[CoversClass(BindingResolver::class)]
final class BindingMetadataMemoisationTest extends TestCase
{
    #[Test]
    public function theSameRouteShapeIsDecidedOnceAndReused(): void
    {
        $resolver = new BindingResolver();

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [MemoisationTestController::class, 'showPost'],
        );

        $first = $resolver->resolveForRoute(
            new MatchedRoute($route, ['user' => '1', 'post' => '20']),
            MemoisationTestController::class,
            'showPost',
        );

        // A different request for the same route: different parameter VALUES,
        // identical route shape. Nothing about the decision can differ, so
        // nothing about it should be recomputed.
        $second = $resolver->resolveForRoute(
            new MatchedRoute($route, ['user' => '9', 'post' => '77']),
            MemoisationTestController::class,
            'showPost',
        );

        // Identity, not equality: equal-but-distinct objects would mean the
        // reflection and the path parse ran again and produced the same answer.
        self::assertSame($first['user'], $second['user']);
        self::assertSame($first['post'], $second['post']);
        self::assertSame(['user', 'post'], array_keys($second));
        self::assertTrue($second['post']->scoped);
        self::assertSame('posts', $second['post']->parentRelation);
    }

    #[Test]
    public function twoRoutesOnOneHandlerDoNotShareADecision(): void
    {
        // The handler is the same `Class::method` in both cases and the
        // parameter names are identical, so a memo keyed on the signature alone
        // would serve the second route the first route's relation and resolve
        // the comment through `User::$posts`.
        $resolver = new BindingResolver();

        $posts = $resolver->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], '/users/{user}/posts/{post}', [MemoisationTestController::class, 'showPost']),
                ['user' => '1', 'post' => '20'],
            ),
            MemoisationTestController::class,
            'showPost',
        );

        $comments = $resolver->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], '/users/{user}/comments/{post}', [MemoisationTestController::class, 'showPost']),
                ['user' => '1', 'post' => '20'],
            ),
            MemoisationTestController::class,
            'showPost',
        );

        self::assertSame('posts', $posts['post']->parentRelation);
        self::assertSame('comments', $comments['post']->parentRelation);
    }

    #[Test]
    public function anAbsentOptionalPlaceholderIsADifferentShape(): void
    {
        // `/users/{user}/posts/{post?}` matches both `/users/1/posts/20` and
        // `/users/1/posts`, and the router captures a different parameter set
        // for each. Serving the shorter request the longer one's metadata would
        // hand ModelBinder a binding for a parameter the URL never supplied.
        $resolver = new BindingResolver();

        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post?}',
            [MemoisationTestController::class, 'showPost'],
        );

        $withPost = $resolver->resolveForRoute(
            new MatchedRoute($route, ['user' => '1', 'post' => '20']),
            MemoisationTestController::class,
            'showPost',
        );

        $withoutPost = $resolver->resolveForRoute(
            new MatchedRoute($route, ['user' => '1']),
            MemoisationTestController::class,
            'showPost',
        );

        self::assertSame(['user', 'post'], array_keys($withPost));
        self::assertSame(['user'], array_keys($withoutPost));
    }

    #[Test]
    public function aCompiledEntryIsNotServedToTheRouteNextToIt(): void
    {
        // The compiled map is keyed by route NAME. Two routes can share a path
        // and a handler and still be named differently — an admin mirror of a
        // public route, say — and only one of them has a compiled entry.
        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class),
                'post' => new BindingMeta(class: stdClass::class, keyName: 'uuid', keyType: 'string'),
            ],
        ]);

        $resolver = new BindingResolver(compiledMap: $compiledMap);

        $compiled = $resolver->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], '/users/{user}/posts/{post}', [MemoisationTestController::class, 'showPost'], 'posts.show'),
                ['user' => '1', 'post' => 'e5f1'],
            ),
            MemoisationTestController::class,
            'showPost',
        );

        $reflected = $resolver->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], '/users/{user}/posts/{post}', [MemoisationTestController::class, 'showPost'], 'posts.mirror'),
                ['user' => '1', 'post' => '20'],
            ),
            MemoisationTestController::class,
            'showPost',
        );

        self::assertSame('uuid', $compiled['post']->keyName);
        self::assertSame('id', $reflected['post']->keyName);
    }

    #[Test]
    public function aRefusedContainmentIsRefusedEveryTime(): void
    {
        // Fail-closed has to survive the memo. A cached "no answer" that came
        // back as an empty binding map instead of a refusal would resolve the
        // post globally from the second request onwards — the bypass, delayed
        // by one request.
        $resolver = new BindingResolver();

        $matched = new MatchedRoute(
            new Route([Method::GET], '/compare/{user}/{post}', [MemoisationTestController::class, 'showPost']),
            ['user' => '1', 'post' => '20'],
        );

        $codes = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $resolver->resolveForRoute($matched, MemoisationTestController::class, 'showPost');
                self::fail('Expected the undeterminable containment to be refused.');
            } catch (ModelBindingException $e) {
                $codes[] = $e->getCode();
            }
        }

        self::assertSame([500, 500], $codes);
    }

    #[Test]
    public function twoResolversDecideIndependently(): void
    {
        // The memo belongs to the resolver instance, exactly as the kernel's
        // handler descriptors belong to the kernel instance. A static cache
        // would leak one application's explicit bindings into another's
        // decisions — and inside the test suite, into the next test.
        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [MemoisationTestController::class, 'showPost'],
        );

        $plain = new BindingResolver();
        $withRoot = new BindingResolver(explicitBindings: [
            new ExplicitBinding(parameter: 'post', modelClass: stdClass::class, scope: BindingScope::Root),
        ]);

        $scoped = $plain->resolveForRoute(
            new MatchedRoute($route, ['user' => '1', 'post' => '20']),
            MemoisationTestController::class,
            'showPost',
        );

        $global = $withRoot->resolveForRoute(
            new MatchedRoute($route, ['user' => '1', 'post' => '20']),
            MemoisationTestController::class,
            'showPost',
        );

        self::assertSame(BindingScope::Contained, $scoped['post']->scope);
        self::assertSame(BindingScope::Root, $global['post']->scope);
    }

    #[Test]
    public function twoHandlersOnOnePathDoNotShareADecision(): void
    {
        // Same path, same parameter names, different controller method: one
        // type-hints both models, the other only the child. The second must
        // still be refused rather than served the first one's metadata.
        $resolver = new BindingResolver();

        $both = $resolver->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], '/users/{user}/posts/{post}', [MemoisationTestController::class, 'showPost']),
                ['user' => '1', 'post' => '20'],
            ),
            MemoisationTestController::class,
            'showPost',
        );

        self::assertTrue($both['post']->scoped);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $resolver->resolveForRoute(
            new MatchedRoute(
                new Route([Method::GET], '/users/{user}/posts/{post}', [MemoisationTestController::class, 'showPostOnly']),
                ['user' => '1', 'post' => '20'],
            ),
            MemoisationTestController::class,
            'showPostOnly',
        );
    }
}

/**
 * Controller shapes the memo has to keep apart.
 */
final class MemoisationTestController
{
    public function showPost(stdClass $user, stdClass $post): void {}

    public function showPostOnly(stdClass $post): void {}
}
