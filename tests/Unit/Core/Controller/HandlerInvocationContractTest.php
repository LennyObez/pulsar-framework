<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Controller;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Core\Controller\ArgumentResolverChain;
use Pulsar\Core\Controller\ArgumentResolverRegistryInterface;
use Pulsar\Core\Controller\HandlerArgumentResolverInterface;
use Pulsar\Core\Controller\HandlerSignature;
use Pulsar\Core\Kernel;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Route;

use function array_map;
use function get_class;
use function get_debug_type;
use function ini_get;
use function ini_set;
use function is_array;
use function is_file;
use function is_object;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

/**
 * The kernel's controller-invocation contract, shape by shape.
 *
 * ## Why this test exists and what it is worth
 *
 * `Kernel::resolveHandlerArguments()` decides how EVERY controller in EVERY
 * application built on this framework is called. A change there is a change to
 * the public behaviour of code the framework never sees, and it is invisible to
 * a test suite that dispatches one or two example handlers: the framework's own
 * controllers are a biased sample of the shapes applications actually write.
 *
 * That is not hypothetical. An earlier revision of the resolver work stopped
 * building the argument list at the first parameter nothing could fill. It was
 * covered by tests, it read as strictly safer, and it turned ELEVEN ordinary
 * handler shapes from 200 into 500 — none of which involved a resolver, a bound
 * model, or any part of the feature being added. The defect was found by
 * differential execution and not by any test in the suite, because no test in
 * the suite enumerated shapes; each asserted one example of the shape its author
 * had in mind.
 *
 * So this file enumerates instead. Every case below states the handler shape,
 * the route, and the EXACT argument list the handler must receive. The expected
 * values were not written by hand: they were captured by driving one identical
 * harness through `Kernel::handle()` against the pre-change kernel — HEAD's
 * `src/` extracted with `git archive` into a separate tree, isolation verified
 * by reflecting the loaded `Kernel`'s file path — and transcribed verbatim. The
 * table IS the previous kernel's behaviour.
 *
 * ## The property, not the examples
 *
 * Each shape is asserted TWICE: once against a kernel with an empty resolver
 * chain, and once against a kernel with a resolver registered that claims
 * nothing. Those two runs must agree with the table and with each other. That
 * pair is the property worth protecting — **registering a resolver does not
 * change how any handler is called** — and it is what stops the next change to
 * the seam breaking every application. Asserting the full argument list rather
 * than the status code is deliberate: a shifted argument that happens to
 * type-check still returns 200.
 *
 * Shapes that legitimately fail are pinned as failures with the handler never
 * entered, so a change that starts CALLING them is caught as surely as one that
 * stops.
 *
 * The claimed-value half of the contract — what a resolver's value buys and
 * where it is allowed to land — is asserted at the end, where there is no
 * previous behaviour to be differential against.
 */
#[CoversClass(Kernel::class)]
#[CoversClass(ArgumentResolverChain::class)]
final class HandlerInvocationContractTest extends TestCase
{
    /**
     * Every handler shape, with the argument list captured from the pre-change
     * kernel.
     *
     * @return iterable<string, array{
     *     kind: string,
     *     target: string,
     *     pattern: string,
     *     path: string,
     *     status: int,
     *     args: list<string>|null,
     * }>
     */
    public static function handlerShapes(): iterable
    {
        // --- baseline controller shapes -----------------------------------
        yield 'no parameters' => self::shape('method', 'none', '/none', '/none', 200, []);
        yield 'request only' => self::shape('method', 'requestOnly', '/r', '/r', 200, ['request']);
        yield 'request + array (legacy)' => self::shape('method', 'requestArray', '/ra/{slug}', '/ra/abc', 200, ['request', 'array{"slug":"abc"}']);
        yield 'array only (legacy)' => self::shape('method', 'arrayOnly', '/ao/{slug}', '/ao/abc', 200, ['array{"slug":"abc"}']);
        yield 'scalar only' => self::shape('method', 'scalar', '/s/{slug}', '/s/abc', 200, ["string('abc')"]);
        yield 'request + scalar' => self::shape('method', 'requestScalar', '/rs/{slug}', '/rs/abc', 200, ['request', "string('abc')"]);
        yield 'two scalars' => self::shape('method', 'twoScalars', '/two/{a}/{b}', '/two/av/bv', 200, ["string('av')", "string('bv')"]);
        yield 'default used' => self::shape('method', 'scalarWithDefault', '/swd/{slug}', '/swd/abc', 200, ['request', "string('abc')", "string('overview')"]);
        yield 'default overridden by route' => self::shape('method', 'scalarWithDefault', '/swd2/{slug}/{tab}', '/swd2/abc/x', 200, ['request', "string('abc')", "string('x')"]);

        // --- a required parameter the route cannot fill ---------------------
        // Every one of these is a latent application bug: a value is delivered
        // to a parameter it was not meant for. It is the APPLICATION's bug, in
        // the application's code, and the framework has always called them this
        // way. Eleven of them regressed to 500 once; they are pinned here.
        yield 'gap then required' => self::shape('method', 'gapThenRequired', '/g1/{present}', '/g1/pv', 500, null);
        yield 'gap then optional' => self::shape('method', 'gapThenOptional', '/g2/{present}', '/g2/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'request, gap, optional' => self::shape('method', 'requestGapThenOptional', '/g3/{present}', '/g3/pv', 200, ['request', "string('pv')", "string('dflt')"]);
        yield 'nullable gap then optional' => self::shape('method', 'nullableGapThenOptional', '/g4/{present}', '/g4/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'untyped gap then optional' => self::shape('method', 'untypedGapThenOptional', '/g5/{present}', '/g5/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'union gap then optional' => self::shape('method', 'unionGapThenOptional', '/g6/{present}', '/g6/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'mixed gap then optional' => self::shape('method', 'mixedGapThenOptional', '/g7/{present}', '/g7/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'gap then variadic' => self::shape('method', 'gapThenVariadic', '/g8/{rest}', '/g8/rv', 200, ["string('rv')", 'array[]']);
        yield 'gap then two fillable' => self::shape('method', 'gapThenTwoPresent', '/g9/{b}/{c}', '/g9/bv/cv', 200, ["string('bv')", "string('cv')", "string('dflt')"]);
        yield 'two gaps then two optional' => self::shape('method', 'twoGapsThenTwoOptional', '/g10/{p1}/{p2}', '/g10/x/y', 200, ["string('x')", "string('y')", "string('d1')", "string('d2')"]);
        yield 'gap with no route parameters' => self::shape('method', 'gapThenOptional', '/g11', '/g11', 200, ["string('dflt')", "string('dflt')"]);

        // --- variadics ------------------------------------------------------
        yield 'variadic only' => self::shape('method', 'variadicOnly', '/v1', '/v1', 200, ['array[]']);
        yield 'scalar then variadic' => self::shape('method', 'scalarThenVariadic', '/v2/{slug}', '/v2/abc', 200, ["string('abc')", 'array[]']);
        yield 'variadic named like a route parameter' => self::shape('method', 'variadicNamedLikeRoute', '/v3/{rest}', '/v3/rv', 200, ['array["rv"]']);

        // --- optional / nullable ---------------------------------------------
        yield 'all optional, none filled' => self::shape('method', 'allOptional', '/o1', '/o1', 200, ["string('a')", "string('b')"]);
        yield 'all optional, second filled' => self::shape('method', 'allOptional', '/o2/{b}', '/o2/bv', 200, ["string('a')", "string('bv')"]);
        yield 'nullable with default' => self::shape('method', 'nullableWithDefault', '/o3', '/o3', 200, ['null']);
        yield 'nullable required, filled' => self::shape('method', 'nullableRequiredFilled', '/o4/{slug}', '/o4/abc', 200, ["string('abc')"]);
        yield 'nullable required, unfilled' => self::shape('method', 'nullableRequiredMissing', '/o5', '/o5', 500, null);

        // --- exotic types -----------------------------------------------------
        yield 'union type filled from route' => self::shape('method', 'unionFilled', '/x1/{user}', '/x1/u-1', 200, ["string('u-1')"]);
        yield 'intersection type from route' => self::shape('method', 'intersectionMissing', '/x2/{user}', '/x2/u-1', 500, null);
        yield 'intersection type with default' => self::shape('method', 'intersectionWithDefault', '/x3', '/x3', 200, ['null']);
        yield 'untyped parameter' => self::shape('method', 'untypedFilled', '/x4/{slug}', '/x4/abc', 200, ["string('abc')"]);
        yield 'by-reference parameter' => self::shape('method', 'byRef', '/x5/{slug}', '/x5/abc', 200, ["string('abc')"]);
        yield 'request + by-reference parameter' => self::shape('method', 'requestByRef', '/x6/{slug}', '/x6/abc', 200, ['request', "string('abc')"]);
        yield 'object type named like a route parameter' => self::shape('method', 'objectTypedNamedLikeRoute', '/x7/{user}', '/x7/u-1', 500, null);
        yield 'int type named like a route parameter' => self::shape('method', 'intTypedNamedLikeRoute', '/x8/{id}', '/x8/5', 500, null);
        yield 'bool type named like a route parameter' => self::shape('method', 'boolTypedNamedLikeRoute', '/x9/{flag}', '/x9/1', 500, null);

        // --- arity mismatch ---------------------------------------------------
        yield 'more route parameters than declared' => self::shape('method', 'fewerThanRoute', '/a1/{a}/{extra}', '/a1/av/ev', 200, ["string('av')"]);
        yield 'more parameters than the route supplies' => self::shape('method', 'moreThanRoute', '/a2/{a}', '/a2/av', 500, null);
        yield 'declaration order differs from the path' => self::shape('method', 'reversedOrder', '/a3/{a}/{b}', '/a3/av/bv', 200, ["string('bv')", "string('av')"]);
        yield 'request in a non-leading position' => self::shape('method', 'requestSecond', '/a4/{slug}', '/a4/abc', 500, null);
        yield 'array in a non-leading position' => self::shape('method', 'arraySecondNoRequest', '/a5/{slug}', '/a5/abc', 500, null);

        // --- invokables --------------------------------------------------------
        yield 'invokable' => self::shape('invokable', ContractInvokable::class, '/i1/{slug}', '/i1/abc', 200, ['request', "string('abc')"]);
        yield 'invokable with a gap' => self::shape('invokable', ContractInvokableGap::class, '/i2/{present}', '/i2/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'invokable with no parameters' => self::shape('invokable', ContractInvokableNone::class, '/i3', '/i3', 200, []);
        yield 'invokable with array (legacy)' => self::shape('invokable', ContractInvokableArray::class, '/i4/{slug}', '/i4/abc', 200, ['request', 'array{"slug":"abc"}']);

        // --- closures and other callables --------------------------------------
        // A callable handler is NEVER reflected: it receives ($request, $params)
        // whatever it declares. The three 500s below are that contract, and they
        // are pinned so that a change which starts reflecting callables — and so
        // makes closure routes model-bindable — cannot land unnoticed.
        yield 'closure: request + array' => self::shape('handler', 'closureRequestArray', '/c1/{slug}', '/c1/abc', 200, ['request', 'array{"slug":"abc"}']);
        yield 'closure: request only' => self::shape('handler', 'closureRequestOnly', '/c2/{slug}', '/c2/abc', 200, ['request']);
        yield 'closure: no parameters' => self::shape('handler', 'closureNoParams', '/c3', '/c3', 200, []);
        yield 'closure: scalar only' => self::shape('handler', 'closureScalarOnly', '/c4/{slug}', '/c4/abc', 500, null);
        yield 'first-class callable' => self::shape('handler', 'firstClassCallable', '/c5/{slug}', '/c5/abc', 500, null);
        yield 'object array callable' => self::shape('handler', 'objectArrayCallable', '/c6/{slug}', '/c6/abc', 200, ['request', 'array{"slug":"abc"}']);
        yield 'static array callable' => self::shape('handler', 'staticArrayCallable', '/c7/{slug}', '/c7/abc', 500, null);

        // --- optional placeholders ---------------------------------------------
        // An absent `{page?}` still arrives as an empty string, so it fills its
        // parameter rather than leaving a gap. Pinned because the opposite would
        // be a very quiet change: the handler would start seeing the NEXT
        // segment's value.
        yield 'optional placeholder, sole parameter, absent' => self::shape('method', 'scalar', '/op1/{slug?}', '/op1', 500, null);
        yield 'optional placeholder absent, required after' => self::shape('method', 'pageThenSlug', '/op2/{page?}/tail/{slug}', '/op2/tail/abc', 200, ["string('')", "string('abc')"]);
        yield 'optional placeholder absent, default after' => self::shape('method', 'pageThenSlugDefault', '/op3/{page?}/tail/{slug}', '/op3/tail/abc', 200, ["string('')", "string('abc')"]);
        yield 'optional placeholder present' => self::shape('method', 'pageThenSlugDefault', '/op4/{page?}/tail/{slug}', '/op4/7/tail/abc', 200, ["string('7')", "string('abc')"]);

        // --- the same shapes behind route-level middleware ----------------------
        yield 'behind middleware: request + scalar' => self::shape('method-mw', 'requestScalar', '/mw1/{slug}', '/mw1/abc', 200, ['request', "string('abc')"]);
        yield 'behind middleware: gap then optional' => self::shape('method-mw', 'gapThenOptional', '/mw2/{present}', '/mw2/pv', 200, ["string('pv')", "string('dflt')"]);
        yield 'behind middleware: legacy array' => self::shape('method-mw', 'requestArray', '/mw3/{slug}', '/mw3/abc', 200, ['request', 'array{"slug":"abc"}']);
    }

    /**
     * @param list<string>|null $args
     *
     * @return array{kind: string, target: string, pattern: string, path: string, status: int, args: list<string>|null}
     */
    private static function shape(
        string $kind,
        string $target,
        string $pattern,
        string $path,
        int $status,
        ?array $args,
    ): array {
        return [
            'kind' => $kind,
            'target' => $target,
            'pattern' => $pattern,
            'path' => $path,
            'status' => $status,
            'args' => $args,
        ];
    }

    /**
     * The baseline: an application that has never heard of the resolver chain.
     *
     * @param list<string>|null $args
     */
    #[Test]
    #[DataProvider('handlerShapes')]
    public function anEmptyChainCallsEveryHandlerExactlyAsThePreviousKernelDid(
        string $kind,
        string $target,
        string $pattern,
        string $path,
        int $status,
        ?array $args,
    ): void {
        $observed = $this->dispatchShape($kind, $target, $pattern, $path, null);

        self::assertSame($status, $observed['status'], 'status changed for this handler shape');
        self::assertSame($args, $observed['args'], 'argument list changed for this handler shape');
    }

    /**
     * The property. Registering a resolver must be invisible to every handler it
     * does not claim a parameter of — which is every handler in every
     * application that installs a persistence extension and does not use route
     * model binding on a given route.
     *
     * @param list<string>|null $args
     */
    #[Test]
    #[DataProvider('handlerShapes')]
    public function aResolverThatClaimsNothingCallsEveryHandlerTheSameWay(
        string $kind,
        string $target,
        string $pattern,
        string $path,
        int $status,
        ?array $args,
    ): void {
        $observed = $this->dispatchShape($kind, $target, $pattern, $path, new ContractSilentResolver());

        self::assertSame($status, $observed['status'], 'a registered resolver changed the status of this shape');
        self::assertSame($args, $observed['args'], 'a registered resolver changed the argument list of this shape');
    }

    /**
     * A resolver that claims a name this handler does not declare is not a
     * claim on this handler, so it must not change the delivery mode either.
     * The gap shape is the sensitive one: it is delivered positionally, so the
     * route value still lands in `$missing`.
     */
    #[Test]
    public function aClaimOnAnUndeclaredNameLeavesTheCallUntouched(): void
    {
        $observed = $this->dispatchShape(
            'method',
            'gapThenOptional',
            '/claim-elsewhere/{present}',
            '/claim-elsewhere/pv',
            new ContractFixedResolver(['someOtherName' => 'ignored']),
        );

        self::assertSame(200, $observed['status']);
        self::assertSame(["string('pv')", "string('dflt')"], $observed['args']);
    }

    // -----------------------------------------------------------------------
    // What a claim buys: delivery to the parameter it names, or nothing
    // -----------------------------------------------------------------------

    #[Test]
    public function aClaimedValueReachesItsParameterWhenTheListIsAligned(): void
    {
        $entity = new ContractEntity('u-1');

        $observed = $this->dispatchShape(
            'method',
            'entityThenScalar',
            '/b1/{user}/{tab}',
            '/b1/u-1/settings',
            new ContractFixedResolver(['user' => $entity]),
        );

        self::assertSame(200, $observed['status']);
        self::assertSame(['object<' . ContractEntity::class . '>', "string('settings')"], $observed['args']);
    }

    /**
     * The case the delivery-mode switch exists for.
     *
     * `$missing` cannot be filled, so under positional delivery the claimed
     * entity would slide into its slot — a value the framework resolved and
     * authorized for `$user`, handed to a parameter that never asked for it.
     * The call is made by name instead, so the entity cannot move and PHP
     * reports the parameter that has no value.
     */
    #[Test]
    public function aClaimedValueIsNeverSlidIntoTheSlotOfAParameterNothingCanFill(): void
    {
        $observed = $this->dispatchShape(
            'method',
            'gapThenEntity',
            '/b2/{user}',
            '/b2/u-1',
            new ContractFixedResolver(['user' => new ContractEntity('u-1')]),
        );

        self::assertSame(500, $observed['status']);
        self::assertNull($observed['args'], 'the handler must not be entered on a misaligned list');
    }

    /**
     * The same protection when the gap is AFTER the claim: the defaulted
     * parameter behind the gap must not slide forward into it.
     */
    #[Test]
    public function aGapAfterAClaimAlsoForcesDeliveryByName(): void
    {
        $observed = $this->dispatchShape(
            'method',
            'entityThenGapThenOptional',
            '/b3/{user}',
            '/b3/u-1',
            new ContractFixedResolver(['user' => new ContractEntity('u-1')]),
        );

        self::assertSame(500, $observed['status']);
        self::assertNull($observed['args']);
    }

    /**
     * Aligned lists stay positional even with a claim, which is what keeps a
     * variadic named like a route parameter collecting the value positionally
     * rather than under its own name.
     */
    #[Test]
    public function anAlignedClaimStillCollectsAVariadicPositionally(): void
    {
        $entity = new ContractEntity('u-1');

        $observed = $this->dispatchShape(
            'method',
            'entityThenVariadic',
            '/b4/{user}/{rest}',
            '/b4/u-1/rv',
            new ContractFixedResolver(['user' => $entity]),
        );

        self::assertSame(200, $observed['status']);
        self::assertSame(['object<' . ContractEntity::class . '>', 'array["rv"]'], $observed['args']);
    }

    /** A claim of null is a claim, and beats both the route value and the default. */
    #[Test]
    public function aNullClaimBeatsTheRouteValueAndTheDefault(): void
    {
        $observed = $this->dispatchShape(
            'method',
            'scalar',
            '/b5/{slug}',
            '/b5/abc',
            new ContractFixedResolver(['slug' => null]),
        );

        // The handler declares `string $slug`, so null is refused at the call
        // site: the claim reached the parameter it named rather than falling
        // through to the route value.
        self::assertSame(500, $observed['status']);
        self::assertNull($observed['args']);
    }

    /** The legacy array shape never consults the chain at all. */
    #[Test]
    public function theLegacyArrayShapeIsUnreachableByAClaim(): void
    {
        $observed = $this->dispatchShape(
            'method',
            'requestArray',
            '/b6/{slug}',
            '/b6/abc',
            new ContractFixedResolver(['slug' => 'claimed', 'params' => ['forced' => true]]),
        );

        self::assertSame(200, $observed['status']);
        self::assertSame(['request', 'array{"slug":"abc"}'], $observed['args']);
    }

    // -----------------------------------------------------------------------
    // Harness
    // -----------------------------------------------------------------------

    /**
     * @return array{status: int, args: list<string>|null}
     */
    private function dispatchShape(
        string $kind,
        string $target,
        string $pattern,
        string $path,
        ?HandlerArgumentResolverInterface $resolver,
    ): array {
        ContractRecorder::reset();

        $kernel = new Kernel();

        if ($resolver !== null) {
            /** @var ArgumentResolverRegistryInterface $registry */
            $registry = $kernel->container()->get(ArgumentResolverRegistryInterface::class);
            $registry->add($resolver);
        }

        /** @var mixed $handler */
        $handler = match ($kind) {
            'method', 'method-mw' => $this->controllerHandler($kernel, $target),
            'invokable' => $this->invokableHandler($kernel, $target),
            'handler' => self::callableHandler($target),
            default => self::fail('unknown handler kind: ' . $kind),
        };

        // Route-level middleware puts the handler behind a pipeline instead of
        // the kernel's direct call. That is a different code path to the
        // handler, so the shapes that matter are run through both.
        $middleware = [];

        if ($kind === 'method-mw') {
            $kernel->container()->instance(ContractPassThroughMiddleware::class, new ContractPassThroughMiddleware());
            $middleware = [ContractPassThroughMiddleware::class];
        }

        $kernel->router()->add(new Route(
            methods: [Method::GET],
            path: $pattern,
            handler: $handler,
            middleware: $middleware,
        ));

        $response = $this->withErrorLogSilenced(
            static fn(): ResponseInterface => $kernel->handle(
                new ServerRequest(method: 'GET', uri: 'http://localhost' . $path),
            ),
        );

        return [
            'status' => $response->getStatusCode(),
            'args' => ContractRecorder::$arguments === null
                ? null
                : array_map(self::describe(...), ContractRecorder::$arguments),
        ];
    }

    /** @return array{class-string, string} */
    private function controllerHandler(Kernel $kernel, string $method): array
    {
        $kernel->container()->instance(ContractController::class, new ContractController());

        return [ContractController::class, $method];
    }

    /**
     * Instantiated from a closed set rather than `new $class()`: the provider
     * carries plain strings, and a dynamic instantiation here would be a hole
     * both analysers report and neither can check.
     */
    private function invokableHandler(Kernel $kernel, string $class): string
    {
        $instance = match ($class) {
            ContractInvokable::class => new ContractInvokable(),
            ContractInvokableGap::class => new ContractInvokableGap(),
            ContractInvokableNone::class => new ContractInvokableNone(),
            ContractInvokableArray::class => new ContractInvokableArray(),
            default => self::fail('unknown invokable: ' . $class),
        };

        $kernel->container()->instance($class, $instance);

        return $class;
    }

    private static function callableHandler(string $key): mixed
    {
        return match ($key) {
            'closureRequestArray' => static fn(ServerRequestInterface $r, array $p): ResponseInterface => ContractRecorder::put([$r, $p]),
            'closureRequestOnly' => static fn(ServerRequestInterface $r): ResponseInterface => ContractRecorder::put([$r]),
            'closureNoParams' => static fn(): ResponseInterface => ContractRecorder::put([]),
            'closureScalarOnly' => static fn(string $slug): ResponseInterface => ContractRecorder::put([$slug]),
            'firstClassCallable' => new ContractController()->scalar(...),
            'objectArrayCallable' => [new ContractController(), 'requestArray'],
            'staticArrayCallable' => [ContractController::class, 'staticScalar'],
            default => self::fail('unknown callable handler: ' . $key),
        };
    }

    /**
     * A stable, comparable rendering of one argument. Values, not just types:
     * a shifted argument that happens to type-check is exactly the failure this
     * file exists to catch, and only the value distinguishes it.
     */
    private static function describe(mixed $value): string
    {
        if ($value instanceof ServerRequestInterface) {
            return 'request';
        }

        if (is_object($value)) {
            return 'object<' . get_class($value) . '>';
        }

        if (is_array($value)) {
            return 'array' . json_encode($value);
        }

        if ($value === null) {
            return 'null';
        }

        return get_debug_type($value) . '(' . var_export($value, true) . ')';
    }

    /**
     * The kernel reports an unhandled throwable through error_log(), which the
     * CLI SAPI writes to the runner's own output. Many shapes here provoke a
     * 500 deliberately, so the sink is pointed at a temporary file.
     *
     * @param callable(): ResponseInterface $dispatch
     */
    private function withErrorLogSilenced(callable $dispatch): ResponseInterface
    {
        $previous = ini_get('error_log');
        $sink = tempnam(sys_get_temp_dir(), 'pulsar-contract-');
        self::assertIsString($sink, 'could not create a sink for error_log');

        ini_set('error_log', $sink);

        try {
            return $dispatch();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);

            if (is_file($sink)) {
                unlink($sink);
            }
        }
    }
}

/**
 * Records the arguments a handler was entered with. Null means the handler was
 * never entered, which several shapes assert.
 */
final class ContractRecorder
{
    /** @var list<mixed>|null */
    public static ?array $arguments = null;

    public static function reset(): void
    {
        self::$arguments = null;
    }

    /**
     * @param list<mixed> $arguments
     */
    public static function put(array $arguments): ResponseInterface
    {
        self::$arguments = $arguments;

        return Response::html('ok');
    }
}

interface ContractAlpha {}

interface ContractBeta {}

final class ContractEntity implements ContractAlpha, ContractBeta
{
    public function __construct(public string $id = 'e') {}
}

/**
 * Every handler shape under test, in one class so the provider can name them.
 *
 * The shapes are deliberately unlovely — a required parameter no route can
 * fill, a variadic named after a route parameter, the request in second
 * position. Applications contain all of them, which is the point: the contract
 * has to hold for the code people write, not for the code the framework's own
 * controllers happen to be.
 */
final class ContractController
{
    public function none(): ResponseInterface
    {
        return ContractRecorder::put([]);
    }

    public function requestOnly(ServerRequestInterface $r): ResponseInterface
    {
        return ContractRecorder::put([$r]);
    }

    /** @param array<string, string> $p */
    public function requestArray(ServerRequestInterface $r, array $p): ResponseInterface
    {
        return ContractRecorder::put([$r, $p]);
    }

    /** @param array<string, string> $p */
    public function arrayOnly(array $p): ResponseInterface
    {
        return ContractRecorder::put([$p]);
    }

    public function scalar(string $slug): ResponseInterface
    {
        return ContractRecorder::put([$slug]);
    }

    public function requestScalar(ServerRequestInterface $r, string $slug): ResponseInterface
    {
        return ContractRecorder::put([$r, $slug]);
    }

    public function twoScalars(string $a, string $b): ResponseInterface
    {
        return ContractRecorder::put([$a, $b]);
    }

    public function scalarWithDefault(
        ServerRequestInterface $r,
        string $slug,
        string $tab = 'overview',
    ): ResponseInterface {
        return ContractRecorder::put([$r, $slug, $tab]);
    }

    public function gapThenRequired(string $missing, string $present): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }

    public function gapThenOptional(string $missing, string $present = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }

    public function requestGapThenOptional(
        ServerRequestInterface $r,
        string $missing,
        string $present = 'dflt',
    ): ResponseInterface {
        return ContractRecorder::put([$r, $missing, $present]);
    }

    public function nullableGapThenOptional(?string $missing, string $present = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }

    /**
     * Deliberately untyped in the declaration — that IS the shape under test —
     * so the type is stated in PHPDoc instead.
     *
     * @param mixed $missing
     */
    public function untypedGapThenOptional($missing, string $present = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }

    public function unionGapThenOptional(ContractEntity|string $missing, string $present = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }

    public function mixedGapThenOptional(mixed $missing, string $present = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }

    public function gapThenVariadic(string $missing, string ...$rest): ResponseInterface
    {
        return ContractRecorder::put([$missing, $rest]);
    }

    public function gapThenTwoPresent(string $missing, string $b, string $c = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $b, $c]);
    }

    public function twoGapsThenTwoOptional(
        string $m1,
        string $m2,
        string $p1 = 'd1',
        string $p2 = 'd2',
    ): ResponseInterface {
        return ContractRecorder::put([$m1, $m2, $p1, $p2]);
    }

    public function variadicOnly(string ...$rest): ResponseInterface
    {
        return ContractRecorder::put([$rest]);
    }

    public function scalarThenVariadic(string $slug, string ...$rest): ResponseInterface
    {
        return ContractRecorder::put([$slug, $rest]);
    }

    public function variadicNamedLikeRoute(string ...$rest): ResponseInterface
    {
        return ContractRecorder::put([$rest]);
    }

    public function allOptional(string $a = 'a', string $b = 'b'): ResponseInterface
    {
        return ContractRecorder::put([$a, $b]);
    }

    public function nullableWithDefault(?string $slug = null): ResponseInterface
    {
        return ContractRecorder::put([$slug]);
    }

    public function nullableRequiredFilled(?string $slug): ResponseInterface
    {
        return ContractRecorder::put([$slug]);
    }

    public function nullableRequiredMissing(?string $missing): ResponseInterface
    {
        return ContractRecorder::put([$missing]);
    }

    public function unionFilled(ContractEntity|string $user): ResponseInterface
    {
        return ContractRecorder::put([$user]);
    }

    public function intersectionMissing(ContractAlpha&ContractBeta $user): ResponseInterface
    {
        return ContractRecorder::put([$user]);
    }

    public function intersectionWithDefault((ContractAlpha&ContractBeta)|null $user = null): ResponseInterface
    {
        return ContractRecorder::put([$user]);
    }

    /**
     * Untyped by design; see {@see untypedGapThenOptional()}.
     *
     * @param mixed $slug
     */
    public function untypedFilled($slug): ResponseInterface
    {
        return ContractRecorder::put([$slug]);
    }

    public function byRef(string &$slug): ResponseInterface
    {
        return ContractRecorder::put([$slug]);
    }

    public function requestByRef(ServerRequestInterface $r, string &$slug): ResponseInterface
    {
        return ContractRecorder::put([$r, $slug]);
    }

    public function objectTypedNamedLikeRoute(ContractEntity $user): ResponseInterface
    {
        return ContractRecorder::put([$user]);
    }

    public function intTypedNamedLikeRoute(int $id): ResponseInterface
    {
        return ContractRecorder::put([$id]);
    }

    public function boolTypedNamedLikeRoute(bool $flag): ResponseInterface
    {
        return ContractRecorder::put([$flag]);
    }

    public function fewerThanRoute(string $a): ResponseInterface
    {
        return ContractRecorder::put([$a]);
    }

    public function moreThanRoute(string $a, string $b, string $c): ResponseInterface
    {
        return ContractRecorder::put([$a, $b, $c]);
    }

    public function reversedOrder(string $b, string $a): ResponseInterface
    {
        return ContractRecorder::put([$b, $a]);
    }

    public function requestSecond(string $slug, ServerRequestInterface $r): ResponseInterface
    {
        return ContractRecorder::put([$slug, $r]);
    }

    /** @param array<string, string> $p */
    public function arraySecondNoRequest(string $slug, array $p): ResponseInterface
    {
        return ContractRecorder::put([$slug, $p]);
    }

    public static function staticScalar(string $slug): ResponseInterface
    {
        return ContractRecorder::put([$slug]);
    }

    public function pageThenSlug(string $page, string $slug): ResponseInterface
    {
        return ContractRecorder::put([$page, $slug]);
    }

    public function pageThenSlugDefault(string $page, string $slug = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$page, $slug]);
    }

    // --- shapes used by the claimed-value half ---------------------------
    public function entityThenScalar(ContractEntity $user, string $tab): ResponseInterface
    {
        return ContractRecorder::put([$user, $tab]);
    }

    public function gapThenEntity(string $missing, ContractEntity $user): ResponseInterface
    {
        return ContractRecorder::put([$missing, $user]);
    }

    public function entityThenGapThenOptional(
        ContractEntity $user,
        string $missing,
        string $tab = 'dflt',
    ): ResponseInterface {
        return ContractRecorder::put([$user, $missing, $tab]);
    }

    public function entityThenVariadic(ContractEntity $user, string ...$rest): ResponseInterface
    {
        return ContractRecorder::put([$user, $rest]);
    }
}

final class ContractInvokable
{
    public function __invoke(ServerRequestInterface $r, string $slug): ResponseInterface
    {
        return ContractRecorder::put([$r, $slug]);
    }
}

final class ContractInvokableGap
{
    public function __invoke(string $missing, string $present = 'dflt'): ResponseInterface
    {
        return ContractRecorder::put([$missing, $present]);
    }
}

final class ContractInvokableNone
{
    public function __invoke(): ResponseInterface
    {
        return ContractRecorder::put([]);
    }
}

final class ContractInvokableArray
{
    /** @param array<string, string> $p */
    public function __invoke(ServerRequestInterface $r, array $p): ResponseInterface
    {
        return ContractRecorder::put([$r, $p]);
    }
}

/**
 * Adds a pipeline frame and nothing else, so a shape can be dispatched through
 * the route-middleware path as well as the kernel's direct call.
 */
final readonly class ContractPassThroughMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

/** The shape every well-behaved resolver takes on a miss. */
final readonly class ContractSilentResolver implements HandlerArgumentResolverInterface
{
    /**
     * @param array<string, string> $routeParameters
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function resolve(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array {
        return [];
    }
}

final readonly class ContractFixedResolver implements HandlerArgumentResolverInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(private array $claims) {}

    /**
     * @param array<string, string> $routeParameters
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function resolve(
        HandlerSignature $signature,
        ServerRequestInterface $request,
        array $routeParameters,
    ): array {
        return $this->claims;
    }
}
