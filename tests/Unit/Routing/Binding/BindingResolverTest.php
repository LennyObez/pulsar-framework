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
    public function aNestedBindingIsScopedByTheSegmentInFrontOfIt(): void
    {
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');

        // Without these two fields ModelBinder cannot enter its scoped branch,
        // and the child resolves globally — the authorization bypass.
        self::assertTrue($result['post']->scoped);
        self::assertSame('posts', $result['post']->parentRelation);
        self::assertSame('user', $result['post']->parentParameter);

        // The first bound segment has no parent to be constrained by.
        self::assertFalse($result['user']->scoped);
        self::assertNull($result['user']->parentRelation);
    }

    #[Test]
    public function resolveImplicitScopesOnItsOwn(): void
    {
        // The reflection path is what a development install runs on every
        // request, and it is the one that produced unscoped metadata for a
        // nested route. Called directly, without the explicit-override pass, it
        // still has to hand back a scoped child.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveImplicit(BindingResolverTestController::class, 'showPost', $matched);

        self::assertTrue($result['post']->scoped);
        self::assertSame('posts', $result['post']->parentRelation);
        self::assertFalse($result['user']->scoped);
    }

    #[Test]
    public function bindingsAreOrderedByThePathNotByTheControllerSignature(): void
    {
        // ModelBinder feeds each resolved model forward as the parent of the
        // next, so the order decides which model constrains the child. Reading
        // it off the signature would scope the user by the post.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'showPostReversed']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPostReversed');

        self::assertSame(['user', 'post'], array_keys($result));
        self::assertTrue($result['post']->scoped);
        self::assertFalse($result['user']->scoped);
    }

    #[Test]
    public function adjacentPlaceholdersNameNoRelationAndAreRefused(): void
    {
        // /compare/{user}/{post} puts one resource placeholder straight in
        // front of another. The path asserts containment and names nothing to
        // check it through, so the child does not resolve — reading it globally
        // is the same bypass with the relation segment filed off.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/compare/{user}/{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');
    }

    #[Test]
    public function aPlaceholderSharingItsSegmentIsRefusedRatherThanResolvedGlobally(): void
    {
        // `posts-{post}` has no literal segment of its own to name a relation,
        // and it is still inside {user}.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user}/posts-{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');
    }

    #[Test]
    public function aPartialPlaceholderIsNotAResourceAndRefusesToStandInForOne(): void
    {
        // /v{version}/users/{user} reads as a version prefix to a human and as
        // `/u{user}/posts/{post}` to a parser — the same shape, one meaning a
        // containment and one not. This used to resolve {user} as a root, which
        // is the correct answer for the version and a containment bypass for the
        // user, so the ambiguous half now refuses and the author states which
        // one they meant.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/v{version}/users/{user}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['version' => '2', 'user' => '42']);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');
    }

    #[Test]
    public function aPartialPlaceholderPrefixIsAnsweredByDeclaringTheChildAsARoot(): void
    {
        // The escape, so the refusal above is a question with an answer rather
        // than a route shape the framework has taken away. One typed line, in
        // the place every other scope declaration lives.
        $resolver = new BindingResolver([
            new ExplicitBinding(parameter: 'user', modelClass: stdClass::class, scope: BindingScope::Root),
        ]);

        $route = new Route([Method::GET], '/v{version}/users/{user}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['version' => '2', 'user' => '42']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertSame(BindingScope::Root, $result['user']->scope);
        self::assertFalse($result['user']->scoped);
    }

    #[Test]
    public function aNestedChildWhoseParentIsBoundToNothingIsRefused(): void
    {
        // The route path says the post is one of the user's. Nothing says what
        // a user is, so nothing can check that, so the post does not resolve.
        // The controller signature is what used to decide this, and deciding it
        // there is what returned another user's post.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'showPostOnly']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPostOnly');
    }

    #[Test]
    public function anExplicitRootDeclarationIsHowANestedChildResolvesGlobally(): void
    {
        // The escape hatch, and the only one: a typed declaration naming the
        // parameter it applies to.
        $resolver = new BindingResolver(explicitBindings: [
            new ExplicitBinding(parameter: 'post', modelClass: stdClass::class, scope: BindingScope::Root),
        ]);

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');

        self::assertSame(BindingScope::Root, $result['post']->scope);
        self::assertFalse($result['post']->scoped);
        self::assertNull($result['post']->parentRelation);
    }

    #[Test]
    public function anExplicitBindingThatSaysNothingAboutScopeKeepsTheCompiledOne(): void
    {
        // Router::model() is also used to swap a resolver. Doing that must not
        // discard a relation the compiled map recorded for a segment the path
        // cannot name.
        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    scope: BindingScope::Contained,
                    parentRelation: 'authoredPosts',
                ),
            ],
        ]);

        $resolver = new BindingResolver(
            explicitBindings: [
                new ExplicitBinding(parameter: 'post', modelClass: stdClass::class, resolverClass: stdClass::class),
            ],
            compiledMap: $compiledMap,
        );

        $route = new Route([Method::GET], '/users/{user}/blog-posts/{post}', [BindingResolverTestController::class, 'showPost'], 'posts.show');
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');

        self::assertSame('authoredPosts', $result['post']->parentRelation);
        self::assertSame('user', $result['post']->parentParameter);
        self::assertSame(stdClass::class, $result['post']->customResolver);
    }

    #[Test]
    public function aScopedChildStillCarriesItsCustomKey(): void
    {
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/users/{user:slug}/posts/{post:uuid}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => 'john-doe', 'post' => 'e5f1']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');

        self::assertSame('slug', $result['user']->keyName);
        self::assertSame('uuid', $result['post']->keyName);
        self::assertSame('string', $result['post']->keyType);
        self::assertTrue($result['post']->scoped);
        self::assertSame('posts', $result['post']->parentRelation);
    }

    #[Test]
    public function aCompiledScopeIsAuthoritativeOverThePathSegment(): void
    {
        // The compiled map is the escape hatch for a path segment that cannot
        // name the relation, so a recorded scope is never rewritten from the
        // path — otherwise the map could not override anything.
        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class),
                'post' => new BindingMeta(
                    class: stdClass::class,
                    scope: BindingScope::Contained,
                    parentRelation: 'authoredPosts',
                ),
            ],
        ]);

        $resolver = new BindingResolver(compiledMap: $compiledMap);

        $route = new Route([Method::GET], '/users/{user}/blog-posts/{post}', [BindingResolverTestController::class, 'showPost'], 'posts.show');
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');

        self::assertSame('authoredPosts', $result['post']->parentRelation);
    }

    #[Test]
    public function aCompiledBindingWithNoRecordedScopeStillGainsThePathScope(): void
    {
        // A map built before scoping was recorded — or by hand — records
        // neither field. Leaving it unscoped on a nested path would reopen the
        // bypass for exactly the deployments that enable compiled mode.
        $compiledMap = new CompiledBindingMap([
            'posts.show' => [
                'user' => new BindingMeta(class: stdClass::class),
                'post' => new BindingMeta(class: stdClass::class),
            ],
        ]);

        $resolver = new BindingResolver(compiledMap: $compiledMap);

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'showPost'], 'posts.show');
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');

        self::assertTrue($result['post']->scoped);
        self::assertSame('posts', $result['post']->parentRelation);
    }

    #[Test]
    public function anExplicitlyBoundChildIsScopedTheSameWayAnImplicitOneIs(): void
    {
        // Router::model() binds a parameter the controller does not type-hint.
        // It reaches the resolver like any other binding, so it must be
        // constrained like any other binding.
        $resolver = new BindingResolver(explicitBindings: [
            new ExplicitBinding(parameter: 'post', modelClass: stdClass::class),
        ]);

        $route = new Route([Method::GET], '/users/{user}/posts/{post}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertSame(['user', 'post'], array_keys($result));
        self::assertTrue($result['post']->scoped);
        self::assertSame('posts', $result['post']->parentRelation);
    }

    #[Test]
    public function aPlaceholderBehindAnotherOneInItsOwnSegmentIsRefused(): void
    {
        // `/{user}-{post}` decided both parameters from the state in front of
        // the WHOLE segment, because the parser advanced that state once per
        // segment rather than once per placeholder. At the top of a path it
        // reads "nothing contains me", so both came back BindingScope::Root and
        // the post was resolved on its own key with nothing checked.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/{user}-{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        try {
            $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');
            self::fail('a placeholder sharing its segment with the one in front of it must not decide a scope');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
            self::assertStringContainsString('{post}', $e->getMessage());
            self::assertStringContainsString('{user}-{post}', $e->getMessage());
        }
    }

    #[Test]
    public function theFirstPlaceholderInASharedSegmentStillDecidesAScope(): void
    {
        // And the rule stops exactly there. {user} is the first placeholder in
        // `{user}-{post}` and nothing precedes the segment, so it is a root —
        // the same answer `/u{user}` gives, for the same reason. Only what
        // stands BEHIND a placeholder inside one segment is unreadable.
        $resolver = new BindingResolver();

        $route = new Route([Method::GET], '/{user}-{post}', [BindingResolverTestController::class, 'show']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $result = $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'show');

        self::assertSame(['user'], array_keys($result));
        self::assertSame(BindingScope::Root, $result['user']->scope);
        self::assertFalse($result['user']->scoped);
    }

    #[Test]
    public function aSharedSegmentIsNotAParentForThePlaceholdersInsideIt(): void
    {
        // The segment is opaque from both ends. `{user}` does not become the
        // parent of `{post}` by sharing a segment with it, so declaring a
        // relation for `{post}` does not produce a scoped binding — there is no
        // parameter for it to resolve through, and the route refuses rather
        // than silently dropping the declared relation.
        $resolver = new BindingResolver([
            new ExplicitBinding(
                parameter: 'post',
                modelClass: stdClass::class,
                scope: BindingScope::Contained,
                parentRelation: 'posts',
            ),
        ]);

        $route = new Route([Method::GET], '/{user}-{post}', [BindingResolverTestController::class, 'showPost']);
        $matched = new MatchedRoute($route, ['user' => '1', 'post' => '20']);

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $resolver->resolveForRoute($matched, BindingResolverTestController::class, 'showPost');
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

    public function showPost(stdClass $user, stdClass $post): void {}

    public function showPostReversed(stdClass $post, stdClass $user): void {}

    public function showById(int $id): void {}

    public function showWithExtra(stdClass $user, stdClass $post): void {}

    public function showPostOnly(stdClass $post): void {}
}
