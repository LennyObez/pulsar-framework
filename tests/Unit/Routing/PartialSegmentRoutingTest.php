<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingException;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use Pulsar\Routing\RoutingException;

use function array_map;
use function in_array;

/**
 * A route whose first segment holds a placeholder still routes.
 *
 * `/u{user}/posts/{post}` is the shape the containment work is written around:
 * `u{user}` holds a placeholder without being a resource, so the binding layer
 * refuses to treat it as a parent rather than silently resolving `{post}`
 * globally. That refusal is only reachable if the request reaches the binding
 * layer at all — and it stopped doing so.
 *
 * The first-segment bucket index keys a route by its first path segment and
 * looks a request up by the same. `firstStaticSegment()` skipped a segment
 * only when it BEGAN with `{`, so `/u{user}/posts/{post}` was indexed under the
 * literal string `u{user}` — a key that only the request path `/u{user}/...`
 * could ever produce. `/u1/posts/20` looked in the bucket `u1`, found nothing,
 * fell through to the cold scan, and came back:
 *
 *     405 Method "GET" not allowed for path "/u1/posts/20". Allowed: GET, HEAD
 *
 * The method it refused, in the `Allow` header of its own refusal. Two defects
 * behind one symptom: any partial-segment placeholder in the first segment was
 * unroutable, and the 405 fallback described it as a method problem so nobody
 * looked at the index.
 *
 * Both sides are pinned here. The routes match; the binding layer still refuses
 * to unscope their children; and a fallback that finds a route accepting the
 * requested method says the index is broken instead of blaming the method.
 */
#[CoversClass(Router::class)]
#[CoversClass(RoutingException::class)]
final class PartialSegmentRoutingTest extends TestCase
{
    /**
     * Every shape `PathContainmentScopingTest` guards, as the router sees it.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function partialSegmentRoutes(): iterable
    {
        yield 'literal prefix' => ['/u{user}/posts/{post}', '/u1/posts/20'];
        yield 'symbol prefix' => ['/@{user}/posts/{post}', '/@1/posts/20'];
        yield 'literal suffix' => ['/{user}-x/posts/{post}', '/1-x/posts/20'];
        yield 'two in a segment' => ['/{user}{other}/posts/{post}', '/1x/posts/20'];
        yield 'placeholder alone' => ['/u{user}', '/u1'];
    }

    #[Test]
    #[DataProvider('partialSegmentRoutes')]
    public function aFirstSegmentHoldingAPlaceholderStillRoutes(string $pattern, string $path): void
    {
        $router = new Router();
        $router->add(new Route([Method::GET], $pattern, [PartialSegmentController::class, 'show'], 'partial.show'));

        $matched = $router->match(Method::GET, $path);

        self::assertSame($pattern, $matched->route->path);
        self::assertSame('1', $matched->parameter('user'));
    }

    #[Test]
    public function theBucketedNeighbourStillRoutesToo(): void
    {
        // The fix moves partial-segment routes into the catch-all bucket. The
        // point of the bucket index is that `/users/...` requests do not walk
        // them, so the ordinary shape has to keep working beside them — and the
        // catch-all has to be merged in, or the partial route is unreachable
        // again the moment a bucketed route exists.
        $router = new Router();
        $router->add(new Route([Method::GET], '/users/{user}/posts/{post}', [PartialSegmentController::class, 'show'], 'users.show'));
        $router->add(new Route([Method::GET], '/u{user}/posts/{post}', [PartialSegmentController::class, 'show'], 'u.show'));

        self::assertSame('/users/{user}/posts/{post}', $router->match(Method::GET, '/users/1/posts/20')->route->path);
        self::assertSame('/u{user}/posts/{post}', $router->match(Method::GET, '/u1/posts/20')->route->path);
    }

    #[Test]
    public function registrationOrderStillDecidesBetweenACatchAllAndAPartialSegment(): void
    {
        // Both live in the catch-all bucket now, so the merge that restores
        // registration order is the only thing keeping first-registered-wins
        // true between them.
        $router = new Router();
        $router->add(new Route([Method::GET], '/{lang}/posts/{post}', [PartialSegmentController::class, 'show'], 'lang.show'));
        $router->add(new Route([Method::GET], '/u{user}/posts/{post}', [PartialSegmentController::class, 'show'], 'u.show'));

        self::assertSame('/{lang}/posts/{post}', $router->match(Method::GET, '/u1/posts/20')->route->path);
    }

    #[Test]
    public function routingTheChildDoesNotUnscopeIt(): void
    {
        // The whole reason the route has to match. `u{user}` is not readable as
        // a parent, so `{post}` must not resolve on its own key — post 20
        // belongs to another user, and the version of this that "worked" handed
        // it over. The refusal is raised from the route shape alone, so no
        // resolver is consulted for either level.
        $router = new Router();
        $router->add(new Route([Method::GET], '/u{user}/posts/{post}', [PartialSegmentController::class, 'showPost'], 'u.posts.show'));

        $matched = $router->match(Method::GET, '/u1/posts/20');

        $resolver = new RecordingResolver();
        $binder = new ModelBinder($resolver, new BindingResolver(), $this->createStub(ContainerInterface::class));

        try {
            (void) $binder->plan($matched);
            self::fail('a child behind an unreadable parent must not be planned');
        } catch (ModelBindingException $e) {
            self::assertSame(500, $e->getCode());
            self::assertStringContainsString('u{user}', $e->getMessage());
        }

        self::assertSame([], $resolver->resolved);
    }

    #[Test]
    public function aRealMethodMismatchIsStillA405AndNeverListsTheMethodItRefused(): void
    {
        // The 405 path is not being removed, only stopped from lying. A route
        // that genuinely does not accept the method still answers 405, with an
        // `Allow` header that genuinely excludes it.
        $router = new Router();
        $router->add(new Route([Method::POST], '/u{user}/posts/{post}', [PartialSegmentController::class, 'show'], 'u.store'));

        try {
            $router->match(Method::GET, '/u1/posts/20');
            self::fail('GET is not registered for this route');
        } catch (RoutingException $e) {
            self::assertSame(405, $e->getCode());

            $allowed = array_map(static fn(Method $m): string => $m->value, $e->allowedMethods);
            self::assertContains('POST', $allowed);
            self::assertNotContains('GET', $allowed);
        }
    }

    #[Test]
    public function anIndexThatMissesARouteItAcceptsIsReportedAsAnIndexFault(): void
    {
        // The mask, pinned from the other side. The cold scan ignores the
        // method, so a route it finds that DOES accept the requested method is
        // one the indexed pass should have returned — the state the bucket bug
        // put the router in, and the state any future index change can put it
        // in again. Serving it from the cold scan is not an option: that scan
        // walks every registered route, including the ones the collision rules
        // deliberately excluded from the match tables.
        //
        // The corruption is applied through the router's own snapshot API,
        // which is the only supported way to reach the index from outside.
        $router = new Router();
        $router->add(new Route([Method::GET], '/users/{user}', [PartialSegmentController::class, 'show'], 'users.show'));

        $snapshot = $router->snapshot();
        $snapshot['dynamicRouteBuckets'] = [];
        $router->restoreFromSnapshot($snapshot);

        try {
            $router->match(Method::GET, '/users/1');
            self::fail('the index was emptied, so the lookup cannot succeed');
        } catch (RoutingException $e) {
            self::assertSame(500, $e->getCode());
            self::assertStringContainsString('Route index inconsistent', $e->getMessage());
            self::assertStringContainsString('/users/{user}', $e->getMessage());
            self::assertFalse(in_array('GET', array_map(
                static fn(Method $m): string => $m->value,
                $e->allowedMethods,
            ), true));
        }
    }

    #[Test]
    public function anUnroutablePathIsStillA404(): void
    {
        $router = new Router();
        $router->add(new Route([Method::GET], '/u{user}/posts/{post}', [PartialSegmentController::class, 'show'], 'u.show'));

        $this->expectException(RoutingException::class);
        $this->expectExceptionCode(404);

        $router->match(Method::GET, '/nothing/here');
    }
}

/**
 * Records what it was asked for, so a test can assert it was asked for nothing.
 *
 * @internal
 */
final class RecordingResolver implements ModelResolverPort
{
    /** @var list<class-string> */
    public array $resolved = [];

    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $this->resolved[] = $modelClass;

        return null;
    }

    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): ?object {
        $this->resolved[] = $modelClass;

        return null;
    }
}

/** @internal */
final class PartialSegmentUser
{
    public function __construct(public int $id = 0) {}
}

/** @internal */
final class PartialSegmentPost
{
    public function __construct(public int $id = 0) {}
}

/** @internal */
final class PartialSegmentController
{
    public function show(PartialSegmentUser $user): void {}

    public function showPost(PartialSegmentUser $user, PartialSegmentPost $post): void {}
}
