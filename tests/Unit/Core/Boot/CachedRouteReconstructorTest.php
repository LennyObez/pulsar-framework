<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Boot;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\CachedBinding;
use Pulsar\Cache\CachedRoute;
use Pulsar\Cache\RouteHandler;
use Pulsar\Cache\RouteHandlerType;
use Pulsar\Core\Boot\CachedRouteReconstructor;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Binding\ExplicitBinding;

#[CoversClass(CachedRouteReconstructor::class)]
final class CachedRouteReconstructorTest extends TestCase
{
    #[Test]
    public function method_handler_becomes_class_method_pair_and_copies_every_field(): void
    {
        $cached = new CachedRoute(
            methods: [Method::GET, Method::HEAD],
            path: '/users/{id}',
            handler: new RouteHandler(RouteHandlerType::Method, 'App\\UserController', 'show'),
            name: 'users.show',
            attributes: ['scope' => 'read'],
            middleware: ['auth'],
            constraints: ['id' => '\d+'],
            host: 'api.example.com',
        );

        $routes = CachedRouteReconstructor::reconstruct([$cached]);
        self::assertNotNull($routes);

        self::assertCount(1, $routes);
        $route = $routes[0];

        self::assertSame([Method::GET, Method::HEAD], $route->methods);
        self::assertSame('/users/{id}', $route->path);
        self::assertSame(['App\\UserController', 'show'], $route->handler);
        self::assertSame('users.show', $route->name);
        self::assertSame(['scope' => 'read'], $route->attributes);
        self::assertSame(['auth'], $route->middleware);
        self::assertSame(['id' => '\d+'], $route->constraints);
        self::assertSame('api.example.com', $route->host);
    }

    #[Test]
    public function invokable_handler_becomes_the_class_string(): void
    {
        $cached = new CachedRoute(
            methods: [Method::POST],
            path: '/checkout',
            handler: new RouteHandler(RouteHandlerType::Invokable, 'App\\CheckoutAction'),
        );

        $reconstructed = CachedRouteReconstructor::reconstruct([$cached]);
        self::assertNotNull($reconstructed);
        $route = $reconstructed[0];

        self::assertSame('App\\CheckoutAction', $route->handler);
    }

    #[Test]
    public function method_handler_with_null_method_defaults_to_invoke(): void
    {
        $cached = new CachedRoute(
            methods: [Method::GET],
            path: '/',
            handler: new RouteHandler(RouteHandlerType::Method, 'App\\HomeController', null),
        );

        $reconstructed = CachedRouteReconstructor::reconstruct([$cached]);
        self::assertNotNull($reconstructed);
        $route = $reconstructed[0];

        self::assertSame(['App\\HomeController', '__invoke'], $route->handler);
    }

    #[Test]
    public function preserves_order_and_count_across_many_routes(): void
    {
        $cached = [
            new CachedRoute([Method::GET], '/a', new RouteHandler(RouteHandlerType::Invokable, 'A')),
            new CachedRoute([Method::GET], '/b', new RouteHandler(RouteHandlerType::Invokable, 'B')),
            new CachedRoute([Method::GET], '/c', new RouteHandler(RouteHandlerType::Invokable, 'C')),
        ];

        $routes = CachedRouteReconstructor::reconstruct($cached);
        self::assertNotNull($routes);

        self::assertSame(['/a', '/b', '/c'], [$routes[0]->path, $routes[1]->path, $routes[2]->path]);
    }

    #[Test]
    public function empty_input_yields_empty_output(): void
    {
        self::assertSame([], CachedRouteReconstructor::reconstruct([]));
    }

    #[Test]
    public function binding_declarations_round_trip_through_the_cache_shape(): void
    {
        // Both directions, because both are this class's job: `Router::model()`
        // runs once, at optimize time, and everything it declared has to survive
        // the trip out to the cache and back in — a cached-route boot never runs
        // those calls again.
        $declared = [
            new ExplicitBinding('setting', self::class, null, BindingScope::Root),
            new ExplicitBinding('post', self::class, null, BindingScope::Contained, 'posts'),
            new ExplicitBinding('user', self::class, self::class),
        ];

        $restored = CachedRouteReconstructor::reconstructBindings(
            CachedRouteReconstructor::forCache($declared),
        );

        self::assertEquals($declared, $restored);
    }

    #[Test]
    public function a_stored_declaration_that_contradicts_itself_refuses_the_whole_list(): void
    {
        // unserialize() does not run constructors, so a stored blob is the one
        // place a Contained binding could arrive without the relation it resolves
        // through — and a null relation reaching BindingResolver is a child
        // resolved through nothing. ExplicitBinding's constructor is the only
        // judge of that pairing, which is why every declaration goes back through
        // it rather than being copied field by field.
        $stored = [
            new CachedBinding('setting', self::class, null, BindingScope::Root, null),
            new CachedBinding('post', self::class, null, BindingScope::Contained, null),
        ];

        self::assertNull(CachedRouteReconstructor::reconstructBindings($stored));
    }

    #[Test]
    public function no_declarations_reconstructs_to_no_declarations(): void
    {
        // Distinct from the refusal above: an application that declares nothing
        // must reach the router with an empty list, not with a discarded cache.
        self::assertSame([], CachedRouteReconstructor::reconstructBindings([]));
        self::assertSame([], CachedRouteReconstructor::forCache([]));
    }

    #[Test]
    public function declarations_that_were_never_stored_are_refused_like_a_contradiction(): void
    {
        // Distinct from BOTH above. `bindings` was added to the #[Api] shape of
        // FrameworkCacheInterface::load() after 1.0.0, so an implementation
        // written against the published contract returns a payload with no such
        // key at all — and the kernel must boot behind it rather than fatal on an
        // undefined index.
        //
        // Null is not "declares nothing", which is the empty list above and a
        // perfectly serviceable cache. It is "cannot say", and a route table whose
        // scope declarations this boot cannot state is not a route table to serve:
        // the caller discards it and reads the route files, where the declarations
        // are made for real.
        self::assertNull(CachedRouteReconstructor::reconstructBindings(null));
    }

    // -----------------------------------------------------------------
    // A payload the framework did not write
    // -----------------------------------------------------------------

    /**
     * {@see \Pulsar\Cache\FrameworkCacheInterface} is stable API an
     * application may implement, so `load()` is a contract rather than a
     * guarantee. A payload carrying `routes` in another shape used to reach
     * property reads on plain arrays — five PHP warnings and then an
     * `UnhandledMatchError`, neither of which the caller's
     * `catch (ModelBindingException)` covers — which is a 500 on every request
     * of an application that was working, produced by a cache whose whole job is
     * to be an optimization.
     */
    #[Test]
    public function refusesARouteTableItCannotRead(): void
    {
        self::assertNull(CachedRouteReconstructor::reconstruct([
            ['methods' => ['GET'], 'path' => '/things/{id}', 'handler' => ['x', 'y']],
        ]));
    }

    #[Test]
    public function refusesARouteTableThatIsNotAListAtAll(): void
    {
        self::assertNull(CachedRouteReconstructor::reconstruct('not a table'));
        self::assertNull(CachedRouteReconstructor::reconstruct(null));
        self::assertNull(CachedRouteReconstructor::reconstruct(42));
    }

    #[Test]
    public function refusesDeclarationsItCannotRead(): void
    {
        self::assertNull(CachedRouteReconstructor::reconstructBindings([
            ['parameter' => null, 'modelClass' => 'X'],
        ]));
        self::assertNull(CachedRouteReconstructor::reconstructBindings('not a list'));
    }
}
