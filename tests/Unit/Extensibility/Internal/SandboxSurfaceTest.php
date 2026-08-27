<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Container\Container;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Internal\ScopedContainerProxy;
use Pulsar\Extensibility\Internal\ScopedRouterProxy;
use Pulsar\Extensibility\Internal\ServiceRestrictionMap;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingScope;
use Pulsar\Routing\Route;
use Pulsar\Routing\Router;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function array_keys;
use function array_merge;
use function array_slice;
use function file;
use function implode;
use function in_array;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * Enumerates both proxies and requires every public method to account for what
 * it lets across the boundary.
 *
 * The two escapes that this exists to make impossible were both introduced by
 * the round of fixes that reported the sandbox closed:
 *
 *  - `ScopedRouterProxy::resource()` and `::apiResource()` were ADDED, asserted
 *    the capability, and then forwarded the resource name to the inner router
 *    unprefixed. Every other registering method prefixed. Nothing compared them.
 *  - `ScopedContainerProxy::contain()` was added and called from `get()` only,
 *    covering one of its two branches, while `decorate()` — which hands
 *    extension code the decorated service — did not call it at all.
 *
 * Both are the same failure: a per-method rule, enforced by whoever remembered
 * it. So this test does not check behaviour method by method. It reads the class
 * and requires each public method to be one of a small number of declared kinds,
 * and to look like that kind. A method added tomorrow appears in none of the
 * lists and fails here, and the author has to say which kind it is and why.
 *
 * The behavioural half is at the bottom: every registering method on the router
 * proxy is DRIVEN, and everything that lands in the host's table is required to
 * be inside the extension's namespace — path and name both. That is the check
 * `resource()` would have failed on the day it was written.
 */
#[CoversClass(ScopedContainerProxy::class)]
#[CoversClass(ScopedRouterProxy::class)]
final class SandboxSurfaceTest extends TestCase
{
    /**
     * Container-proxy methods that must pass their return value through
     * {@see ScopedContainerProxy::contain()}.
     *
     * Anything that can hand back an object belongs here. `get()` is the obvious
     * one and was the only one: `call()` returned whatever the callable
     * returned, straight out.
     *
     * @var list<string>
     */
    private const array CONTAINER_METHODS_THAT_CONTAIN = ['get', 'call', 'decorate'];

    /**
     * Container-proxy methods that hand back an object without calling
     * `contain()`, and why that is not a hole.
     *
     * @var array<string, string>
     */
    private const array CONTAINER_CONTAINED_BY_CONSTRUCTION = [
        'construct' => 'Builds the object itself, filling every constructor parameter through get() — '
            . 'so each parameter is already contained, and the result is a class the extension named '
            . 'holding nothing it could not have resolved. Containing the result on top would mean '
            . 'exchanging an extension\'s own class for the proxy whenever it happens to implement '
            . 'PSR-11, which is the one substitution that would be wrong.',
        'scopedRouter' => 'Returns the scoped router. It is what contain() would return for a router, '
            . 'and it is where contain() gets it from.',
    ];

    /**
     * Container-proxy methods whose return type cannot carry a service, and why.
     *
     * Each entry is checked against the declared return type as well as being
     * listed, so an entry cannot survive the method it names growing an object
     * return.
     *
     * @var array<string, string>
     */
    private const array CONTAINER_NOTHING_TO_CONTAIN = [
        'has' => 'bool. PSR-11 truthfulness; get() still refuses.',
        'bind' => 'void. Registration, not resolution — the value flows the other way, and the '
            . 'concrete is rebound to a scope-bound factory on the way in.',
        'singleton' => 'void. As bind().',
        'instance' => 'void. The extension supplies the object; nothing leaves.',
        'forgetInstance' => 'void.',
        'setResolutionHints' => 'void.',
        'getBindings' => 'list<string>. Service ids, not services. Disclosure of the host id space, '
            . 'gated on ContainerRead and recorded in ADR-0023.',
        'getInstances' => 'list<string>. As getBindings().',
        'provideThroughScope' => 'void. Registers a scope-bound factory for a class the extension '
            . 'named; the object it will build leaves later, through get(), and is contained there.',
    ];

    /**
     * Router-proxy methods that must funnel through
     * {@see ScopedRouterProxy::add()}, the single registration door.
     *
     * @var list<string>
     */
    private const array ROUTER_METHODS_THAT_REGISTER = [
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'any',
        'group',
        'resource',
        'apiResource',
    ];

    /**
     * Router-proxy methods that do not register a route, and why.
     *
     * @var array<string, string>
     */
    private const array ROUTER_NOT_A_REGISTRATION = [
        'add' => 'The door itself. Checked separately: it must call confine().',
        'model' => 'Registers a parameter-to-model binding, which has no path and therefore no '
            . 'prefix that could confine it — so it costs RouteRegisterGlobal instead, which '
            . 'Community and Untrusted do not hold. Checked by ScopedRouterProxyTest.',
        'match' => 'MatchedRoute. Read-only; discloses the host table, recorded in ADR-0023.',
        'routes' => 'list<Route>. As match().',
        'count' => 'int.',
    ];

    // --- The container proxy accounts for every exit -------------------------

    #[Test]
    public function everyContainerProxyMethodAccountsForWhatItReturns(): void
    {
        $declared = array_merge(
            self::CONTAINER_METHODS_THAT_CONTAIN,
            array_keys(self::CONTAINER_CONTAINED_BY_CONSTRUCTION),
            array_keys(self::CONTAINER_NOTHING_TO_CONTAIN),
        );

        foreach (self::publicMethods(ScopedContainerProxy::class) as $name) {
            self::assertContains($name, $declared, sprintf(
                'ScopedContainerProxy::%s() is a new exit from the scope and this test does not know '
                . 'about it. Either it passes what it returns through contain(), or it is contained '
                . 'by construction, or its return type cannot carry a service — say which, in the '
                . 'matching constant, with the reason.',
                $name,
            ));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function containerMethodsThatContain(): iterable
    {
        foreach (self::CONTAINER_METHODS_THAT_CONTAIN as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('containerMethodsThatContain')]
    public function aContainerMethodDeclaredToContainActuallyCallsContain(string $name): void
    {
        self::assertStringContainsString(
            '$this->contain(',
            self::bodyOf(ScopedContainerProxy::class, $name),
            sprintf(
                'ScopedContainerProxy::%s() is declared as containing what it hands over, and does '
                . 'not call contain(). That is the shape decorate() had: it re-bound the container '
                . 'argument and passed the decorated SERVICE through untouched.',
                $name,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function containerMethodsWithNothingToContain(): iterable
    {
        foreach (self::CONTAINER_NOTHING_TO_CONTAIN as $name => $reason) {
            yield $name => [$name, $reason];
        }
    }

    /**
     * A "nothing to contain" claim is about the RETURN TYPE, so it is checked
     * against the return type rather than taken on the reason's word.
     */
    #[Test]
    #[DataProvider('containerMethodsWithNothingToContain')]
    public function aContainerMethodWithNothingToContainReturnsNoObject(string $name, string $reason): void
    {
        self::assertNotSame('', $reason, 'every exemption states its reason');

        $returnType = new ReflectionMethod(ScopedContainerProxy::class, $name)->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertContains(
            $returnType->getName(),
            ['void', 'bool', 'int', 'string', 'float', 'array'],
            sprintf(
                'ScopedContainerProxy::%s() is exempted from containment on the grounds that its '
                . 'return type cannot carry a service, and it now returns %s.',
                $name,
                $returnType->getName(),
            ),
        );
    }

    // --- The router proxy has one registration door --------------------------

    #[Test]
    public function everyRouterProxyMethodIsEitherARegistrationOrExplainsItself(): void
    {
        $declared = array_merge(
            self::ROUTER_METHODS_THAT_REGISTER,
            array_keys(self::ROUTER_NOT_A_REGISTRATION),
        );

        foreach (self::publicMethods(ScopedRouterProxy::class) as $name) {
            self::assertContains($name, $declared, sprintf(
                'ScopedRouterProxy::%s() is new and this test does not know about it. Either it '
                . 'registers through add() — the single door that applies the path prefix, the name '
                . 'prefix and the handler construction scope — or say why it does not. resource() '
                . 'and apiResource() were added without doing either, and forwarded their argument '
                . 'to the inner router unprefixed.',
                $name,
            ));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function routerMethodsThatRegister(): iterable
    {
        foreach (self::ROUTER_METHODS_THAT_REGISTER as $name) {
            yield $name => [$name];
        }
    }

    #[Test]
    #[DataProvider('routerMethodsThatRegister')]
    public function aRegisteringRouterMethodGoesThroughTheOneDoor(string $name): void
    {
        self::assertStringContainsString(
            '$this->add(',
            self::bodyOf(ScopedRouterProxy::class, $name),
            sprintf(
                'ScopedRouterProxy::%s() registers routes without going through add(), which is '
                . 'where confinement is applied. Every method that reaches the inner router on its '
                . 'own has to re-implement the prefixing, and resource() is what happens when one '
                . 'of them does not.',
                $name,
            ),
        );
    }

    #[Test]
    public function theOneDoorConfines(): void
    {
        self::assertStringContainsString(
            '$this->confine(',
            self::bodyOf(ScopedRouterProxy::class, 'add'),
        );
    }

    // --- ...and the door is checked by driving it ----------------------------

    /**
     * Every registering method, driven, with everything it produced required to
     * be inside the extension's namespace.
     *
     * The structural check above says the methods go through `add()`. This says
     * what going through `add()` is worth, without naming a single method: the
     * list is read from the class, the arguments are built from the signatures,
     * and the assertion is made over whatever ended up in the host's table.
     */
    #[Test]
    public function nothingAnyRegisteringMethodProducesEscapesTheExtensionNamespace(): void
    {
        foreach (self::ROUTER_METHODS_THAT_REGISTER as $name) {
            $router = new Router();
            $proxy = self::routerProxy($router);
            $method = new ReflectionMethod(ScopedRouterProxy::class, $name);

            $method->invokeArgs($proxy, self::argumentsFor($method));

            $routes = $router->routes();

            self::assertNotSame([], $routes, sprintf(
                'ScopedRouterProxy::%s() registered nothing, so this proves nothing about it. '
                . 'Give argumentsFor() what it needs to make the call register a route.',
                $name,
            ));

            foreach ($routes as $route) {
                self::assertStringStartsWith('/ext/acme/evil', $route->path, sprintf(
                    'ScopedRouterProxy::%s() put "%s" in the host route table outside the '
                    . 'extension prefix.',
                    $name,
                    $route->path,
                ));

                if ($route->name === null) {
                    continue;
                }

                self::assertStringStartsWith('ext.acme.evil.', $route->name, sprintf(
                    'ScopedRouterProxy::%s() claimed the global route name "%s". A name is a '
                    . 'last-wins key: the prefix confined the path and not the name, so an '
                    . 'extension could re-point every route("login") in the host at itself.',
                    $name,
                    $route->name,
                ));
            }
        }
    }

    /**
     * The handler and middleware classes a route names are bound to the
     * extension's scope, so the framework builds them there rather than from the
     * real container at request time.
     */
    #[Test]
    public function aRoutesHandlerAndMiddlewareAreBoundToTheScope(): void
    {
        $container = self::container();
        $router = new Router();
        $scope = self::scope($container);

        $scope->scopedRouter($router)->add(new Route(
            [Method::GET],
            '/probe',
            [SurfaceProbeController::class, 'index'],
            'probe',
            middleware: [SurfaceProbeMiddleware::class],
        ));

        self::assertContains(SurfaceProbeController::class, $container->getBindings());
        self::assertContains(SurfaceProbeMiddleware::class, $container->getBindings());

        // And what the binding builds goes through the scope, so a constructor
        // asking for the real container is refused rather than filled.
        self::assertInstanceOf(SurfaceProbeController::class, $container->get(SurfaceProbeController::class));
    }

    /**
     * A class the HOST already bound is never re-bound: routing at it cannot
     * take it over.
     */
    #[Test]
    public function aHostBindingIsNeverReplacedByTheScope(): void
    {
        $container = self::container();
        $hosts = new SurfaceProbeController();
        $container->instance(SurfaceProbeController::class, $hosts);

        self::scope($container)->provideThroughScope(SurfaceProbeController::class);

        self::assertSame($hosts, $container->get(SurfaceProbeController::class));
    }

    // --- Helpers -------------------------------------------------------------

    private static function container(): Container
    {
        $container = new Container();
        $container->instance(LoggerInterface::class, new NullLogger());

        return $container;
    }

    /**
     * The probe classes live in this file, so this directory stands in for the
     * extension's own directory — which is what decides whether a routed class
     * counts as code the extension ships.
     */
    private static function scope(?Container $container = null): ScopedContainerProxy
    {
        return new ScopedContainerProxy(
            $container ?? self::container(),
            TrustTier::Community,
            CapabilityPolicy::defaults(),
            ServiceRestrictionMap::defaults(),
            'acme/evil',
            extensionPath: __DIR__,
        );
    }

    /**
     * A class the extension merely NAMES, and does not ship, is left to the
     * framework.
     *
     * `analytics` attaches the framework's own `CsrfMiddleware` to its routes.
     * Claiming it would have moved a framework class's construction inside one
     * extension's tier for the whole process — so the framework's own use of it
     * would answer to that extension's capabilities, and could be made to fail
     * by it. The test asserts the non-claim, because the tempting version of
     * this mechanism claims everything.
     */
    #[Test]
    public function aClassTheExtensionDoesNotShipIsLeftToTheFramework(): void
    {
        $container = self::container();

        self::scope($container)->provideThroughScope(Router::class);

        self::assertNotContains(
            Router::class,
            $container->getBindings(),
            'a framework class the extension only named must not have its construction '
            . 'captured by that extension\'s scope',
        );
    }

    /**
     * An extension with no manifest path has no directory to compare against,
     * so it claims nothing.
     *
     * This is the one case where the mechanism has nothing to work with —
     * programmatic registration, which is what tests do. Pinned rather than left
     * to be discovered, because the alternative reading ("claim everything when
     * we do not know") is the one that captures framework classes.
     */
    #[Test]
    public function anExtensionWithNoDirectoryClaimsNothing(): void
    {
        $container = self::container();

        new ScopedContainerProxy(
            $container,
            TrustTier::Community,
            CapabilityPolicy::defaults(),
            ServiceRestrictionMap::defaults(),
            'acme/evil',
        )->provideThroughScope(SurfaceProbeController::class);

        self::assertNotContains(SurfaceProbeController::class, $container->getBindings());
    }

    private static function routerProxy(Router $router): ScopedRouterProxy
    {
        return self::scope()->scopedRouter($router);
    }

    /**
     * Arguments shaped to make the call actually register something.
     *
     * Built from the signature rather than written per method, so a new
     * registering method is driven by this test the moment it is added to the
     * list — including one whose author forgot the prefix.
     *
     * @return list<mixed>
     */
    private static function argumentsFor(ReflectionMethod $method): array
    {
        $arguments = [];

        foreach ($method->getParameters() as $parameter) {
            $arguments[] = self::argumentFor($parameter);
        }

        return $arguments;
    }

    private static function argumentFor(ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $parameter->getName();

        if ($name === 'callback') {
            // group()'s callback: register one route inside the group, so the
            // group has something to confine.
            return static function (ScopedRouterProxy $sub): void {
                $sub->get('/inner', static fn(): string => 'ok', 'inner');
            };
        }

        if ($name === 'controller' || $name === 'modelClass') {
            return SurfaceProbeController::class;
        }

        if ($name === 'name' && $type instanceof ReflectionNamedType && $type->allowsNull()) {
            return 'probe';
        }

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        return match ($type->getName()) {
            'string' => 'probe',
            'array' => [],
            'callable', 'mixed' => static fn(): string => 'ok',
            Route::class => new Route([Method::GET], '/probe', static fn(): string => 'ok', 'probe'),
            Method::class => Method::GET,
            BindingScope::class => BindingScope::Path,
            default => null,
        };
    }

    /**
     * The source lines of one method.
     *
     * Structure is read from the file because the property under test is
     * structural: "this method funnels through that one". A behavioural probe
     * per method would be a per-method rule, which is the thing that keeps
     * failing.
     */
    private static function bodyOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $file = $reflection->getFileName();
        $start = $reflection->getStartLine();
        $end = $reflection->getEndLine();

        self::assertIsString($file);
        self::assertIsInt($start);
        self::assertIsInt($end);

        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function publicMethods(string $class): array
    {
        $methods = [];

        foreach (new ReflectionClass($class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();

            if ($method->isStatic() || $method->isConstructor() || str_starts_with($name, '__')) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            if (in_array($name, $methods, true) || str_contains($name, 'Deprecated')) {
                continue;
            }

            $methods[] = $name;
        }

        return $methods;
    }
}

/**
 * A route handler the framework would otherwise construct from the real
 * container at request time.
 */
final class SurfaceProbeController
{
    public function index(): string
    {
        return 'ok';
    }
}

/** A route middleware class name, resolved by the pipeline at request time. */
final class SurfaceProbeMiddleware
{
    public function process(): string
    {
        return 'ok';
    }
}
