<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingAuthorization;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ExplicitBinding;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function array_find;

/**
 * Containment in the route path is what scopes a child, and nothing else.
 *
 * Each case is stated as a request outcome rather than as metadata: the
 * resolver spy enforces ownership in `resolveScoped()` and nowhere else, the
 * way every real adapter does, so a binding the framework fails to scope shows
 * up as another tenant's row rather than as wrong-looking metadata.
 *
 * The three shapes are the ones an adversarial review reproduced against a
 * booted kernel while the scoping decision was still read off the controller
 * signature:
 *
 *  a. a nested route whose controller does not ask for the parent;
 *  b. a two-level nest whose controller skips the intermediate level;
 *  c. the same two-level nest with every level asked for — the control.
 */
#[CoversClass(BindingResolver::class)]
#[CoversClass(ModelBinder::class)]
final class PathContainmentScopingTest extends TestCase
{
    #[Test]
    public function aChildIsNotHandedOverWhenTheControllerDeclinesTheParent(): void
    {
        // Shape (a). /users/{user}/posts/{post} handled by show(Post $post).
        // The URL asserts that post 20 sits under user 1; it belongs to user 2.
        // Whether the handler happens to want the user is a convenience of the
        // handler; it cannot decide whether the ownership check runs.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);

        $matched = $this->route('/users/{user}/posts/{post}', 'showPostOnly', ['user' => '1', 'post' => '20']);
        (void) $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function anUncheckableContainmentIsRefusedBeforeAnythingIsRead(): void
    {
        // The same shape, stated as the rule rather than as the leak: nothing
        // declares what {user} is, so the containment the URL asserts cannot be
        // checked, so the child does not resolve. There is no third outcome in
        // which the post comes back unscoped.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route('/users/{user}/posts/{post}', 'showPostOnly', ['user' => '1', 'post' => '20']);
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('an uncheckable containment must not resolve the child');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
        }

        self::assertSame([], $spy->unscopedClasses, 'nothing may be read before the refusal');
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function theParentIsResolvedEvenThoughNoControllerParameterWantsIt(): void
    {
        // Declaring the parent is what makes shape (a) work, and it works by
        // resolving the user — which nothing is going to be handed — purely so
        // there is something to scope the post by.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/users/{user}/posts/{post}', 'showPostOnly', ['user' => '2', 'post' => '20']);
        $models = $this->binder($spy, [
            new ExplicitBinding(parameter: 'user', modelClass: ContainmentUser::class),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame(20, $models['post']->id);
        self::assertSame([ContainmentUser::class], $spy->unscopedClasses);
        self::assertSame(
            [['relation' => 'posts', 'parent' => 'user:2', 'key' => '20']],
            $spy->scopedCalls,
        );
    }

    #[Test]
    public function aDeclaredParentStillRefusesAPostItDoesNotOwn(): void
    {
        // Control for the case above: user 1 does not own post 20, and the
        // declaration does not turn the check off.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        $matched = $this->route('/users/{user}/posts/{post}', 'showPostOnly', ['user' => '1', 'post' => '20']);
        (void) $this->binder($spy, [
            new ExplicitBinding(parameter: 'user', modelClass: ContainmentUser::class),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function aGrandchildIsNeverReparentedOverASkippedLevel(): void
    {
        // Shape (b), the worse defect. Comment 300 was written by user 1 but
        // lives under post 20, which belongs to user 2. The URL says post 10.
        //
        // Scoping the comment to the nearest *bound* ancestor re-parents it to
        // {user} through the same-named `comments` relation, and the request
        // comes back 200 looking fully validated.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route(
                '/users/{user}/posts/{post}/comments/{comment}',
                'showComment',
                ['user' => '1', 'post' => '10', 'comment' => '300'],
            );
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a comment under another user\'s post must not resolve');
        } catch (ModelBindingException) {
            // Refused, which is the whole point.
        }

        self::assertNotContains(
            ['relation' => 'comments', 'parent' => 'user:1', 'key' => '300'],
            $spy->scopedCalls,
            'the comment was re-parented to the user across the skipped post level',
        );
    }

    #[Test]
    public function aSkippedIntermediateLevelIsResolvedOnceItIsDeclared(): void
    {
        // Shape (b) with {post} declared: the comment is now checked against
        // the post the URL names, so it is refused on the merits — a 404 — and
        // the user's own `comments` relation is never consulted.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route(
                '/users/{user}/posts/{post}/comments/{comment}',
                'showComment',
                ['user' => '1', 'post' => '10', 'comment' => '300'],
            );
            (void) $this->binder($spy, [
                new ExplicitBinding(parameter: 'post', modelClass: ContainmentPost::class),
            ])->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a comment under another post must not resolve');
        } catch (ModelBindingException $e) {
            self::assertSame(404, $e->getCode());
        }

        self::assertSame(
            [
                ['relation' => 'posts', 'parent' => 'user:1', 'key' => '10'],
                ['relation' => 'comments', 'parent' => 'post:10', 'key' => '300'],
            ],
            $spy->scopedCalls,
        );
    }

    #[Test]
    public function aFullyNestedRouteResolvesEachLevelThroughTheOneBeforeIt(): void
    {
        // Shape (c): every level is bound. This is the case that already
        // worked, and it has to keep working — level by level, each through the
        // relation named by the segment in front of it.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route(
            '/users/{user}/posts/{post}/comments/{comment}',
            'showCommentFull',
            ['user' => '1', 'post' => '10', 'comment' => '301'],
        );
        $models = $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentUser::class, $models['user']);
        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertInstanceOf(ContainmentComment::class, $models['comment']);

        self::assertSame([ContainmentUser::class], $spy->unscopedClasses);
        self::assertSame(
            [
                ['relation' => 'posts', 'parent' => 'user:1', 'key' => '10'],
                ['relation' => 'comments', 'parent' => 'post:10', 'key' => '301'],
            ],
            $spy->scopedCalls,
        );
    }

    #[Test]
    public function theFullyNestedRouteStillRefusesACommentFromAnotherPost(): void
    {
        // Control for shape (c): the chain refuses on the merits, not because
        // the fixture is empty.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        $matched = $this->route(
            '/users/{user}/posts/{post}/comments/{comment}',
            'showCommentFull',
            ['user' => '1', 'post' => '10', 'comment' => '300'],
        );
        (void) $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function adjacentPlaceholdersNameNoRelationAndAreRefused(): void
    {
        // /compare/{user}/{post} puts a resource placeholder directly in front
        // of another one. The path asserts containment but names no relation to
        // check it through, so the child is refused rather than read globally.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $matched = $this->route('/compare/{user}/{post}', 'showPost', ['user' => '1', 'post' => '20']);
        (void) $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function aPlaceholderSharingItsSegmentIsRefusedRatherThanResolvedGlobally(): void
    {
        // `posts-{post}` has no segment of its own to name a relation, and it
        // still sits under {user}. Resolving it globally is the same bypass
        // wearing a different path.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $matched = $this->route('/users/{user}/posts-{post}', 'showPost', ['user' => '1', 'post' => '20']);
        (void) $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function aDeliberatelyGlobalChildResolvesOnlyWhenItIsDeclaredAsOne(): void
    {
        // The escape hatch. A nested path whose second resource is genuinely
        // global has to say so, in one typed place a reviewer can grep for —
        // and it says so in words, not by leaving something out.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/users/{user}/posts/{post}', 'showPost', ['user' => '1', 'post' => '20']);
        $models = $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'post',
                modelClass: ContainmentPost::class,
                scope: BindingScope::Root,
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        // Post 20 belongs to user 2 and comes back anyway, which is precisely
        // what the declaration asked for.
        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function aSegmentThatCannotNameARelationIsBoundByDeclaringOne(): void
    {
        // The other escape hatch: `blog-posts` is not a property name any PHP
        // class can have, so the path cannot supply the relation and the route
        // would otherwise be refused. Naming it is a declaration, and it still
        // resolves through the parent.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/users/{user}/blog-posts/{post}', 'showPost', ['user' => '1', 'post' => '10']);
        $models = $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'post',
                modelClass: ContainmentPost::class,
                scope: BindingScope::Contained,
                parentRelation: 'posts',
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame(
            [['relation' => 'posts', 'parent' => 'user:1', 'key' => '10']],
            $spy->scopedCalls,
        );
    }

    #[Test]
    public function aDeclaredRelationDoesNotSurviveALostParent(): void
    {
        // A declared relation says how to reach the parent, never that the
        // parent may be skipped: {user} is still resolved and still checked.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        $matched = $this->route('/users/{user}/blog-posts/{post}', 'showPost', ['user' => '1', 'post' => '20']);
        (void) $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'post',
                modelClass: ContainmentPost::class,
                scope: BindingScope::Contained,
                parentRelation: 'posts',
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    // -----------------------------------------------------------------
    // A segment that holds a placeholder without being one
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableParentSegments(): iterable
    {
        yield 'literal prefix' => ['/u{user}/posts/{post}'];
        yield 'symbol prefix' => ['/@{user}/posts/{post}'];
        yield 'literal suffix' => ['/{user}-x/posts/{post}'];
        yield 'two in a segment' => ['/{user}{other}/posts/{post}'];
    }

    #[Test]
    #[DataProvider('unreadableParentSegments')]
    public function aParentSharingItsSegmentIsRefusedRatherThanUnscopingTheChild(string $path): void
    {
        // `u{user}` was never recorded as a preceding resource, and nothing
        // recorded that it had been skipped either — so {post} arrived with no
        // parent, which is the same state as "top of the path", and resolved
        // GLOBALLY. Post 20 belongs to user 2 and came back under user 1.
        //
        // Whether such a segment addresses a resource is not a property of the
        // URL: `/u{user}/posts/{post}` means the user's posts and
        // `/v{version}/posts/{post}` means every post, and a parser sees one
        // string. The ambiguous half fails closed.
        $spy = new ContainmentResolverSpy();
        $parameters = ['user' => '1', 'post' => '20', 'other' => 'x'];

        try {
            $matched = $this->route($path, 'showPost', $parameters);
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a child behind an unreadable segment must not resolve');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
        }

        self::assertSame([], $spy->scopedCalls, 'nothing may be read before the refusal');
        self::assertNotContains(ContainmentPost::class, $spy->unscopedClasses);
    }

    #[Test]
    public function anUnreadableSegmentAtTheTopOfThePathStillResolvesItsOwnPlaceholder(): void
    {
        // The other side of the rule, so it does not read as "any odd segment
        // refuses". `u{user}` has nothing in front of it, so {user} is a root
        // and resolves on its own key. It is only as a PARENT that the segment
        // is unreadable.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/u{user}', 'showUserOnly', ['user' => '1']);
        $models = $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentUser::class, $models['user']);
        self::assertSame([ContainmentUser::class], $spy->unscopedClasses);
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function aRootDeclarationIsTheWayPastAnUnreadableParent(): void
    {
        // The refusal is answerable, in the same one line every other
        // containment refusal is answered in.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/u{user}/posts/{post}', 'showPost', ['user' => '1', 'post' => '20']);
        $models = $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'post',
                modelClass: ContainmentPost::class,
                scope: BindingScope::Root,
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame([], $spy->scopedCalls);
    }

    // -----------------------------------------------------------------
    // What makes a placeholder a resource
    // -----------------------------------------------------------------

    #[Test]
    public function anAddressingPrefixIsAResourcePlaceholderAndSaysSoOutLoud(): void
    {
        // `/{locale}/users/{user}` and `/{owner}/posts/{post}` are the same
        // string to a parser, and only one of them means containment. Occupying
        // a whole segment is therefore the entire test for "is this a resource",
        // and it deliberately over-approximates: a locale, a tenant slug and an
        // API version written {version} are all candidate parents.
        //
        // The consequence is a refusal naming {locale}, not a silent global
        // read. Reading it the other way — "nothing is bound to it, so ignore
        // it" — is exactly the rule that unscopes `/{owner}/posts/{post}`, and
        // the two cannot be told apart, so the safe half is the one that ships.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route('/{locale}/users/{user}', 'showUserOnly', ['locale' => 'fr', 'user' => '1']);
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a resource behind an unbound whole-segment placeholder must not resolve silently');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
            self::assertStringContainsString('locale', $e->getMessage());
        }

        self::assertSame([], $spy->unscopedClasses);
    }

    #[Test]
    public function anAddressingPrefixIsDeclaredAwayInOneLine(): void
    {
        // And the way out is the same declaration as everywhere else, so an
        // application that really does put a locale in front of its resources
        // writes it down once rather than discovering it at runtime.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/{locale}/users/{user}', 'showUserOnly', ['locale' => 'fr', 'user' => '1']);
        $models = $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'user',
                modelClass: ContainmentUser::class,
                scope: BindingScope::Root,
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentUser::class, $models['user']);
    }

    // -----------------------------------------------------------------
    // A segment two placeholders share
    // -----------------------------------------------------------------

    /**
     * One segment carrying two placeholders, in each shape a path can write it.
     *
     * The last two are the same segment seen from the other two sides: nested
     * under a normal one, and with a normal one nested under it.
     *
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function sharedSegments(): iterable
    {
        yield 'hyphen' => ['/{user}-{post}', 'showPost', ['user' => '1', 'post' => '20']];
        yield 'dot' => ['/{user}.{post}', 'showPost', ['user' => '1', 'post' => '20']];
        yield 'underscore' => ['/{user}_{post}', 'showPost', ['user' => '1', 'post' => '20']];
        yield 'no separator' => ['/{user}{post}', 'showPost', ['user' => '1', 'post' => '20']];
        yield 'literal prefix' => ['/owner{user}{post}', 'showPost', ['user' => '1', 'post' => '20']];
        yield 'shared segment under a normal one' => [
            '/users/{user}/{post}-{comment}',
            'showCommentFull',
            ['user' => '1', 'post' => '10', 'comment' => '300'],
        ];
        yield 'normal segment under a shared one' => [
            '/{user}-{other}/posts/{post}',
            'showPost',
            ['user' => '1', 'other' => 'x', 'post' => '20'],
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[Test]
    #[DataProvider('sharedSegments')]
    public function aSharedSegmentRefusesRatherThanResolvingItsPlaceholdersGlobally(
        string $path,
        string $method,
        array $parameters,
    ): void {
        // The founding bug in its last shape. Every placeholder in a segment
        // used to be handed the state from BEFORE that segment, because the
        // parser only advanced it once the whole segment had been read. At the
        // top of a path that state is "nothing in front of me", which is the
        // top-of-path answer, which is BindingScope::Root — so `/{user}-{post}`
        // resolved BOTH globally and handed back post 20, owned by user 2,
        // under a URL naming user 1, with no refusal anywhere.
        //
        // A shared segment is where containment is least legible, not most:
        // `{user}-{post}` and `{tenant}.{resource}` read as containment to a
        // person and are indistinguishable from `{year}-{month}` to a parser.
        // So it fails closed.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route($path, $method, $parameters);
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a placeholder standing behind another one in its own segment must not resolve');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
        }

        self::assertSame([], $spy->unscopedClasses, 'nothing may be read before the refusal');
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function theSecondPlaceholderInASharedSegmentNamesTheSegmentItStandsIn(): void
    {
        // The refusal has to be answerable, so it names the parameter it
        // refused, the segment the two share, and the way out. `unreadableParent`
        // would say the child stands *behind* a segment, which is not what
        // happened: it stands inside one.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route('/{user}-{post}', 'showPost', ['user' => '1', 'post' => '20']);
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a shared segment must refuse');
        } catch (ModelBindingException $e) {
            self::assertStringContainsString('{post}', $e->getMessage());
            self::assertStringContainsString('{user}-{post}', $e->getMessage());
            self::assertStringContainsString('BindingScope::Root', $e->getMessage());
        }
    }

    #[Test]
    public function theFirstPlaceholderInASharedSegmentIsStillReadAsItsOwnResource(): void
    {
        // The other side of the rule, so it does not read as "any segment with
        // two placeholders refuses outright". Nothing is in front of {user}
        // inside `{user}-{other}` and nothing is in front of the segment, so
        // {user} is a root and resolves on its own key — the same answer
        // `/u{user}` already gives. It is what stands BEHIND a placeholder in
        // the same segment that cannot be read.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/{user}-{other}', 'showUserOnly', ['user' => '1', 'other' => 'x']);
        $models = $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentUser::class, $models['user']);
        self::assertSame([ContainmentUser::class], $spy->unscopedClasses);
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function aSharedSegmentIsAnsweredByDeclaringTheChildARoot(): void
    {
        // Answered in the same one typed line every other containment refusal
        // is answered in. Post 20 belongs to user 2 and comes back under a URL
        // naming user 1 — which is exactly what the declaration asked for, and
        // now it is a sentence in the route table rather than a parser gap.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/{user}-{post}', 'showPost', ['user' => '1', 'post' => '20']);
        $models = $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'post',
                modelClass: ContainmentPost::class,
                scope: BindingScope::Root,
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame(20, $models['post']->id);
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function aDeclaredRelationDoesNotMakeTheRestOfASharedSegmentReadable(): void
    {
        // {post} is first in `{post}-{comment}`, so it is contained by {user}
        // and a declared relation is all it needs. That declaration says
        // nothing about {comment}, which stands behind {post} in the same
        // segment — and comment 301 really is post 10's, so the refusal is the
        // rule and not the fixture.
        //
        // BindingScope::Contained is not an escape here and cannot be: a
        // contained binding resolves through a parent PARAMETER, and the thing
        // in front of {comment} is a fragment of a segment, which is not one.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route(
                '/users/{user}/{post}-{comment}',
                'showCommentFull',
                ['user' => '1', 'post' => '10', 'comment' => '301'],
            );
            (void) $this->binder($spy, [
                new ExplicitBinding(
                    parameter: 'post',
                    modelClass: ContainmentPost::class,
                    scope: BindingScope::Contained,
                    parentRelation: 'posts',
                ),
                new ExplicitBinding(
                    parameter: 'comment',
                    modelClass: ContainmentComment::class,
                    scope: BindingScope::Contained,
                    parentRelation: 'comments',
                ),
            ])->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('a declared relation must not make a shared segment legible');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
        }

        self::assertSame([], $spy->unscopedClasses);
        self::assertSame([], $spy->scopedCalls);
    }

    #[Test]
    public function aSharedSegmentIsNeverAParentEvenForItsOwnPlaceholders(): void
    {
        // The segment does not become a parent for the placeholder after it
        // either: declaring {post} contained through `posts` does not make
        // {user} — which shares its segment — the thing it is contained by.
        // There is no reading of `{user}-{post}` on which the framework scopes
        // the post by the user without being told to.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(500);

        $matched = $this->route('/{user}-{post}', 'showPost', ['user' => '1', 'post' => '10']);
        (void) $this->binder($spy, [
            new ExplicitBinding(
                parameter: 'post',
                modelClass: ContainmentPost::class,
                scope: BindingScope::Contained,
                parentRelation: 'posts',
            ),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    // -----------------------------------------------------------------
    // Union and intersection type hints
    // -----------------------------------------------------------------

    #[Test]
    public function aUnionTypeHintDeclaresABindingAndIsScopedLikeAPlainOne(): void
    {
        // `show(Post|string $post)` used to declare NOTHING: the reflection
        // filter required a ReflectionNamedType, so a union was skipped
        // outright. No model was resolved, no policy was consulted, no
        // containment was checked, and the handler got the raw id in a slot that
        // had declared a Post acceptable.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/users/{user}/posts/{post}', 'showPostUnion', ['user' => '1', 'post' => '10']);
        $models = $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame(
            [['relation' => 'posts', 'parent' => 'user:1', 'key' => '10']],
            $spy->scopedCalls,
        );
    }

    #[Test]
    public function aUnionTypeHintDoesNotEscapeTheContainmentCheck(): void
    {
        // The half that matters: post 20 belongs to user 2, and a union hint
        // does not buy it a way past the check.
        $spy = new ContainmentResolverSpy();

        $this->expectException(ModelBindingException::class);
        $this->expectExceptionCode(404);

        $matched = $this->route('/users/{user}/posts/{post}', 'showPostUnion', ['user' => '1', 'post' => '20']);
        (void) $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );
    }

    #[Test]
    public function anIntersectionTypeHintBindsTheOneClassInIt(): void
    {
        // `Post&Marker` names one class and one interface. A resolver is asked
        // for a concrete class, so the interface is not a candidate and the
        // hint is unambiguous.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/posts/{post}', 'showPostIntersection', ['post' => '10']);
        $models = $this->binder($spy)->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
    }

    #[Test]
    public function aTypeHintNamingTwoModelsIsRefusedRatherThanGuessed(): void
    {
        // Picking the first would make which model the route resolves — and
        // therefore which policy the authorization hook is asked about — depend
        // on the order the union was written in.
        $spy = new ContainmentResolverSpy();

        try {
            $matched = $this->route('/posts/{post}', 'showAmbiguous', ['post' => '10']);
            (void) $this->binder($spy)->bind(
                $matched,
                $this->createStub(ServerRequestInterface::class),
                new ResolutionContext(),
                self::noPolicy($matched),
            );
            self::fail('an ambiguous bound type must not resolve');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
            self::assertStringContainsString(ContainmentPost::class, $e->getMessage());
            self::assertStringContainsString(ContainmentComment::class, $e->getMessage());
        }

        self::assertSame([], $spy->unscopedClasses);
    }

    #[Test]
    public function anAmbiguousTypeHintIsAnsweredByNamingTheModel(): void
    {
        // The refusal above tells the author to name the model with
        // Router::model(), so taking that advice has to work. It only does
        // because the refusal is raised AFTER the explicit bindings are applied
        // — raised inside the reflection it would be unanswerable, and the
        // message would be sending people in a circle.
        $spy = new ContainmentResolverSpy();

        $matched = $this->route('/posts/{post}', 'showAmbiguous', ['post' => '10']);
        $models = $this->binder($spy, [
            new ExplicitBinding(parameter: 'post', modelClass: ContainmentPost::class),
        ])->bind(
            $matched,
            $this->createStub(ServerRequestInterface::class),
            new ResolutionContext(),
            self::noPolicy($matched),
        );

        self::assertInstanceOf(ContainmentPost::class, $models['post']);
        self::assertSame(10, $models['post']->id);
    }

    /**
     * The decision these tests bind under: none at all.
     *
     * Every property in this file is about what the PATH says a child is
     * contained by, and each has to hold with authorization out of the picture.
     * A containment bypass that only a policy hook catches is still a bypass —
     * the hook would be asked about a row the route had no business reaching.
     * Standard is the only preset an exemption can be constructed for, which is
     * how that statement gets made out loud at every call site.
     */
    private static function noPolicy(MatchedRoute $matched): BindingAuthorization
    {
        return BindingAuthorization::unenforcedPreset($matched, BindingPreset::Standard);
    }

    /**
     * @param list<ExplicitBinding> $explicit
     */
    private function binder(ModelResolverPort $resolver, array $explicit = []): ModelBinder
    {
        return new ModelBinder(
            $resolver,
            new BindingResolver($explicit),
            $this->createStub(ContainerInterface::class),
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function route(string $path, string $method, array $parameters): MatchedRoute
    {
        return new MatchedRoute(
            new Route([Method::GET], $path, [ContainmentController::class, $method]),
            $parameters,
        );
    }
}

/**
 * Enforces ownership in resolveScoped() and nowhere else.
 *
 * `resolve()` is deliberately ownership-blind, the way a plain "find by primary
 * key" is, so a binding the framework fails to scope comes back with the row.
 *
 * @internal
 */
final class ContainmentResolverSpy implements ModelResolverPort
{
    /** @var list<array{relation: string, parent: string, key: string|int}> */
    public array $scopedCalls = [];

    /** @var list<class-string> */
    public array $unscopedClasses = [];

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $this->unscopedClasses[] = $modelClass;

        return match ($modelClass) {
            ContainmentUser::class => array_find(
                self::users(),
                static fn(ContainmentUser $user): bool => (string) $user->id === (string) $keyValue,
            ),
            ContainmentPost::class => array_find(
                self::posts(),
                static fn(ContainmentPost $post): bool => (string) $post->id === (string) $keyValue,
            ),
            default => array_find(
                self::comments(),
                static fn(ContainmentComment $comment): bool => (string) $comment->id === (string) $keyValue,
            ),
        };
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
        $parentLabel = match (true) {
            $parent instanceof ContainmentUser => 'user:' . $parent->id,
            $parent instanceof ContainmentPost => 'post:' . $parent->id,
            default => 'unknown',
        };

        $this->scopedCalls[] = ['relation' => $relation, 'parent' => $parentLabel, 'key' => $keyValue];

        // `User::$posts` and `User::$comments` both exist, which is what let a
        // re-parented grandchild pass for a validated one.
        if ($parent instanceof ContainmentUser && $relation === 'posts') {
            return array_find(
                self::posts(),
                static fn(ContainmentPost $post): bool => (string) $post->id === (string) $keyValue
                    && $post->userId === $parent->id,
            );
        }

        if ($parent instanceof ContainmentUser && $relation === 'comments') {
            return array_find(
                self::comments(),
                static fn(ContainmentComment $c): bool => (string) $c->id === (string) $keyValue
                    && $c->authorId === $parent->id,
            );
        }

        if ($parent instanceof ContainmentPost && $relation === 'comments') {
            return array_find(
                self::comments(),
                static fn(ContainmentComment $c): bool => (string) $c->id === (string) $keyValue
                    && $c->postId === $parent->id,
            );
        }

        return null;
    }

    /**
     * @return list<ContainmentUser>
     */
    private static function users(): array
    {
        return [new ContainmentUser(1), new ContainmentUser(2)];
    }

    /**
     * @return list<ContainmentPost>
     */
    private static function posts(): array
    {
        return [new ContainmentPost(10, 1), new ContainmentPost(20, 2)];
    }

    /**
     * @return list<ContainmentComment>
     */
    private static function comments(): array
    {
        // Comment 300 is written by user 1 but lives under user 2's post.
        return [new ContainmentComment(300, 20, 1), new ContainmentComment(301, 10, 1)];
    }
}

/**
 * @internal
 */
final readonly class ContainmentUser
{
    public function __construct(public int $id) {}
}

/**
 * @internal
 */
interface ContainmentMarker {}

/**
 * @internal
 */
final readonly class ContainmentPost implements ContainmentMarker
{
    public function __construct(public int $id, public int $userId) {}
}

/**
 * @internal
 */
final readonly class ContainmentComment
{
    public function __construct(public int $id, public int $postId, public int $authorId) {}
}

/**
 * @internal
 */
final class ContainmentController
{
    public function showPostOnly(ContainmentPost $post): void {}

    public function showUserOnly(ContainmentUser $user): void {}

    public function showPostUnion(ContainmentUser $user, ContainmentPost|string $post): void {}

    public function showPostIntersection(ContainmentPost&ContainmentMarker $post): void {}

    public function showAmbiguous(ContainmentPost|ContainmentComment $post): void {}

    public function showPost(ContainmentUser $user, ContainmentPost $post): void {}

    public function showComment(ContainmentUser $user, ContainmentComment $comment): void {}

    public function showCommentFull(
        ContainmentUser $user,
        ContainmentPost $post,
        ContainmentComment $comment,
    ): void {}
}
