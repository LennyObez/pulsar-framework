<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingAuthorization;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function array_find;

/**
 * Proof that a nested route binds its child through the parent.
 *
 * The binder and the metadata resolver are exercised together against a
 * resolver that enforces ownership only in `resolveScoped()` — exactly the
 * shape of every real adapter, {@see \Pulsar\Extension\Orm\Features\Binding\OrmModelResolver}
 * included. Metadata that fails to mark a binding scoped therefore does not
 * show up as wrong metadata; it shows up as another user's row being returned,
 * which is what these tests assert against.
 */
#[CoversClass(BindingResolver::class)]
#[CoversClass(ModelBinder::class)]
final class ScopedBindingBypassTest extends TestCase
{
    #[Test]
    public function aPostOwnedByAnotherUserDoesNotResolveUnderThisUser(): void
    {
        // The documented example, and the audit's reproduction: post 20 belongs
        // to user 2. Requested as /users/1/posts/20 it must not resolve at all.
        // Resolving it unscoped returns it — every layer above still believing
        // the `{user}` segment constrained the read — which is the bypass.
        $resolver = new ScopedBindingResolverSpy();
        $binder = $this->binder($resolver);
        $matched = $this->nestedRoute(user: '1', post: '20');

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        (void) $binder->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function theSamePostResolvesForTheUserThatOwnsIt(): void
    {
        // Control for the test above: the refusal is the parent constraint and
        // not a broken fixture or an unrelated 404.
        $resolver = new ScopedBindingResolverSpy();
        $matched = $this->nestedRoute(user: '2', post: '20');

        $models = $this->binder($resolver)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ScopedBindingPost::class, $models['post']);
        self::assertSame(20, $models['post']->id);
    }

    #[Test]
    public function theChildIsResolvedThroughTheRelationNamedByThePathSegment(): void
    {
        $resolver = new ScopedBindingResolverSpy();
        $matched = $this->nestedRoute(user: '1', post: '10');

        $models = $this->binder($resolver)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ScopedBindingUser::class, $models['user']);
        self::assertInstanceOf(ScopedBindingPost::class, $models['post']);

        // The `{post}` segment went through resolveScoped() with the user as
        // parent and `posts` — the literal segment in front of it — as the
        // relation. The parent itself went through the unscoped path.
        self::assertSame(
            [['relation' => 'posts', 'parent' => 1, 'key' => '10']],
            $resolver->scopedCalls,
        );
        self::assertSame([ScopedBindingUser::class], $resolver->unscopedClasses);
    }

    #[Test]
    public function aSingleSegmentBindingIsNeverScoped(): void
    {
        // There is no parent to be constrained by, so the binding must take the
        // plain lookup — a scoped call here would resolve nothing and 404 every
        // top-level route in the application.
        $resolver = new ScopedBindingResolverSpy();

        $route = new Route([Method::GET], '/users/{user}', [ScopedBindingController::class, 'showUser']);
        $matched = new MatchedRoute($route, ['user' => '1']);

        $models = $this->binder($resolver)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ScopedBindingUser::class, $models['user']);
        self::assertSame([], $resolver->scopedCalls);
    }

    #[Test]
    public function aParentThatDoesNotResolveStopsTheChainBeforeTheChild(): void
    {
        // User 99 does not exist. The child must not be reached at all: binding
        // it would mean resolving a post under a parent nobody proved exists.
        $resolver = new ScopedBindingResolverSpy();
        $binder = $this->binder($resolver);
        $matched = $this->nestedRoute(user: '99', post: '10');

        try {
            (void) $binder->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a missing parent must not bind');
        } catch (ModelBindingException $e) {
            self::assertSame(404, $e->getCode());
        }

        self::assertSame([], $resolver->scopedCalls);
    }

    private function binder(ModelResolverPort $resolver): ModelBinder
    {
        return new ModelBinder($resolver, new BindingResolver(), $this->createStub(ContainerInterface::class));
    }

    /**
     * These tests are about scoping, not about who may read the row, so they
     * bind under the one preset that permits an exemption at all. The scoping
     * refusal they assert has to hold with authorization out of the picture:
     * a containment bypass that only a policy hook catches is a bypass.
     */
    private static function noPolicy(MatchedRoute $matched): BindingAuthorization
    {
        return BindingAuthorization::unenforcedPreset($matched, BindingPreset::Standard);
    }

    private function nestedRoute(string $user, string $post): MatchedRoute
    {
        $route = new Route(
            [Method::GET],
            '/users/{user}/posts/{post}',
            [ScopedBindingController::class, 'showPost'],
        );

        return new MatchedRoute($route, ['user' => $user, 'post' => $post]);
    }
}

/**
 * A resolver that enforces ownership in resolveScoped() and nowhere else.
 *
 * @internal
 */
final class ScopedBindingResolverSpy implements ModelResolverPort
{
    /** @var list<array{relation: string, parent: int, key: string|int}> */
    public array $scopedCalls = [];

    /** @var list<class-string> */
    public array $unscopedClasses = [];

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $this->unscopedClasses[] = $modelClass;

        if ($modelClass === ScopedBindingUser::class) {
            return array_find(
                self::users(),
                static fn(ScopedBindingUser $user): bool => (string) $user->id === (string) $keyValue,
            );
        }

        // Deliberately ownership-blind, the way a plain "find by id" is.
        return array_find(
            self::posts(),
            static fn(ScopedBindingPost $post): bool => (string) $post->id === (string) $keyValue,
        );
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
        if (!$parent instanceof ScopedBindingUser) {
            return null;
        }

        $this->scopedCalls[] = ['relation' => $relation, 'parent' => $parent->id, 'key' => $keyValue];

        if ($relation !== 'posts' || $modelClass !== ScopedBindingPost::class) {
            return null;
        }

        return array_find(
            self::posts(),
            static fn(ScopedBindingPost $post): bool => (string) $post->id === (string) $keyValue
                && $post->userId === $parent->id,
        );
    }

    /**
     * @return list<ScopedBindingUser>
     */
    private static function users(): array
    {
        return [new ScopedBindingUser(1), new ScopedBindingUser(2)];
    }

    /**
     * @return list<ScopedBindingPost>
     */
    private static function posts(): array
    {
        return [new ScopedBindingPost(10, 1), new ScopedBindingPost(20, 2)];
    }
}

/**
 * @internal
 */
final readonly class ScopedBindingUser
{
    public function __construct(public int $id) {}
}

/**
 * @internal
 */
final readonly class ScopedBindingPost
{
    public function __construct(public int $id, public int $userId) {}
}

/**
 * @internal
 */
final class ScopedBindingController
{
    public function showUser(ScopedBindingUser $user): void {}

    public function showPost(ScopedBindingUser $user, ScopedBindingPost $post): void {}
}
