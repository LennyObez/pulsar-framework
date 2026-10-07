<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Closure;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Attribute\PublicRoute;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\CompiledBindingMap;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function array_filter;
use function array_values;

/**
 * What a caller with no claim on a record can learn from a bound route.
 *
 * The middleware used to compute the identity, bind the model, and only then
 * notice that a regulated preset had nobody to authorize against. So an
 * unauthenticated request for a bound route was answered `404` when the id did
 * not exist and `401` when it did: an existence oracle over every bound
 * resource, with no credentials, on every route in the application.
 *
 * These tests state the property as a comparison rather than as a status code.
 * Two requests that differ only in whether the id names a real row must produce
 * the same status, the same reason phrase, the same headers and the same body —
 * and, because a difference in work is a difference an attacker can time, the
 * resolver must not be called at all. The spy counts every call it receives:
 * zero calls is the stable, CI-safe form of "both requests took the same time",
 * since the resolver is the only thing on this path that reaches a store.
 *
 * ## The unentitled caller is held to the same property
 *
 * The acceptance criterion is "anonymous OR unentitled", and only the first half
 * used to be met: an authenticated caller the policy hook refused was answered
 * `403` for a record that exists and `404` for one that does not, which is the
 * same oracle behind an account that costs nothing to open. That half cannot be
 * closed by ordering — a policy needs the row — so it is closed by the answer,
 * and those tests differ from the anonymous ones in exactly one way: the
 * resolver IS reached for both ids, because it had to be.
 *
 * The paths that DO disclose existence are asserted here too, in the same shape.
 * Each one is a posture the framework documents — a public route that opts out
 * of authorization, the permissive preset — and pinning them down is what keeps
 * the difference between "declared" and "leaked" reviewable.
 */
#[CoversClass(ModelBindingMiddleware::class)]
#[CoversClass(ModelBinder::class)]
final class UnauthenticatedDisclosureTest extends TestCase
{
    private const string EXISTING = DisclosureResolverSpy::RESOLVABLE_ID;

    private const string ABSENT = '999';

    // ── The oracle, closed ───────────────────────────────────────────────

    /**
     * @param (Closure(): ?IdentityInterface)|null $identityResolver
     */
    #[Test]
    #[DataProvider('anonymousCallerProvider')]
    public function anonymousRequestsCannotTellAnExistingIdFromAnAbsentOne(?Closure $identityResolver): void
    {
        // Every shape "no caller" arrives in: no resolver wired at all, a
        // resolver that finds nobody, and the anonymous placeholder the
        // globally piped AuthenticationMiddleware leaves behind.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Banking, $identityResolver);

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT));

        self::assertSame(401, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertSame([], $spy->calls, 'the resolver must not be reached before the caller is known');
    }

    /**
     * @return iterable<string, array{(Closure(): ?IdentityInterface)|null}>
     */
    public static function anonymousCallerProvider(): iterable
    {
        yield 'no identity resolver wired' => [null];
        yield 'resolver finds no identity' => [static fn(): ?IdentityInterface => null];
        yield 'anonymous placeholder' => [static fn(): IdentityInterface => new AnonymousIdentity()];
    }

    #[Test]
    public function theJsonRefusalIsIdenticalForBothIdsToo(): void
    {
        // The negotiated body is where an error message would be, and a message
        // naming the model class and the key it looked for is exactly what the
        // 404 carries. Under this posture neither id gets one.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Banking);

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING, json: true));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT, json: true));

        self::assertSame(401, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertStringNotContainsString(DisclosureRecord::class, (string) $onAbsent->getBody());
        self::assertSame([], $spy->calls);
    }

    #[Test]
    public function aNestedRouteRefusesBeforeEvenItsParentIsRead(): void
    {
        // The parent of a nested route is resolved to scope the child, so it is
        // read first and unconditionally. An anonymous caller must not be able
        // to probe for parents either.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Banking);

        $onExisting = $this->dispatch($middleware, $this->nestedRequest(self::EXISTING, '7'));
        $onAbsent = $this->dispatch($middleware, $this->nestedRequest(self::ABSENT, '7'));

        self::assertSame(401, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertSame([], $spy->calls);
    }

    #[Test]
    public function aMalformedKeyIsNotDistinguishableFromAWellFormedOneEither(): void
    {
        // Key coercion is the other thing that used to run before the caller
        // was known: an `int` key rejected "abc" with a 400 while "42" got as
        // far as the 401. Both are now the same refusal.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware(
            $spy,
            BindingPreset::Banking,
            map: new CompiledBindingMap([
                'records.show' => [
                    'record' => new BindingMeta(class: DisclosureRecord::class, keyName: 'id', keyType: 'int'),
                ],
            ]),
        );

        $onNumeric = $this->dispatch($middleware, $this->request(self::EXISTING));
        $onMalformed = $this->dispatch($middleware, $this->request('abc'));

        self::assertSame(401, $onNumeric->getStatusCode());
        self::assertResponsesIndistinguishable($onNumeric, $onMalformed);
        self::assertSame([], $spy->calls);
    }

    #[Test]
    public function aMisdeclaredRouteAnswersTheAnonymousCallerWithTheSame401(): void
    {
        // `/compare/{left}/{right}` puts one bound placeholder straight after
        // another, so no segment names a relation to reach the child through
        // and the route is refused as unscopable — a 500 decided from the route
        // shape. It is not an existence oracle, but it is route state, and an
        // anonymous caller has no claim on it: the model-independent gate runs
        // first and answers with the same 401 every other bound route gives.
        $spy = new DisclosureResolverSpy();
        $anonymous = $this->middleware($spy, BindingPreset::Banking);

        $refused = $this->dispatch($anonymous, $this->comparisonRequest());

        self::assertSame(401, $refused->getStatusCode());
        self::assertSame([], $spy->calls);

        // The misconfiguration itself is not swallowed: an authenticated caller
        // still gets the 500 that says the route cannot be served.
        $authenticated = $this->middleware($spy, BindingPreset::Banking, fn(): IdentityInterface => $this->identity());

        self::assertSame(500, $this->dispatch($authenticated, $this->comparisonRequest())->getStatusCode());
        self::assertSame([], $spy->calls, 'an unscopable route resolves nothing for anyone');
    }

    #[Test]
    public function theIllegalOptOutIsRefusedForWhatTheRouteDeclaresNotForWhatExists(): void
    {
        // `_without_authorization` on a route that is not #[PublicRoute] is
        // forbidden under a regulated preset. That refusal reads the route, not
        // the row, so it belongs in front of the resolver: deciding it after
        // binding made the answer 403 for a real id and 404 for an absent one,
        // which is the same oracle wearing a different status code.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Banking, fn(): IdentityInterface => $this->identity());
        $optOut = ['_without_authorization' => true];

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING, attributes: $optOut));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT, attributes: $optOut));

        self::assertSame(403, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertSame([], $spy->calls);
    }

    #[Test]
    public function aPublicRouteThatDidNotOptOutStillRefusesBothIdsAlike(): void
    {
        // #[PublicRoute] alone exempts nothing: it only makes the
        // `_without_authorization` opt-out legal. Under a regulated preset an
        // anonymous caller is still refused — and now identically for both ids.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Banking);

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING, handler: [DisclosurePublicController::class, 'show']));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT, handler: [DisclosurePublicController::class, 'show']));

        self::assertSame(401, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertSame([], $spy->calls);
    }

    // ── The regression this ordering must not cause ──────────────────────

    #[Test]
    public function aRouteThatBindsNothingIsHandedOnWhateverThePresetSays(): void
    {
        // The gate keys on what the route BINDS, never on whether it has
        // parameters. A `{note}` that no controller parameter type-hints binds
        // no model, has no authorization decision to make, and must reach the
        // handler for an anonymous caller under the strictest preset there is —
        // otherwise every string-parameter route in every application starts
        // answering 401.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Banking);

        $route = new Route([Method::GET], '/notes/{note}', [DisclosureUnboundController::class, 'ping'], 'notes.show');
        $request = new ServerRequest('GET', '/notes/anything')
            ->withAttribute('_route', new MatchedRoute($route, ['note' => 'anything']));

        $response = $this->dispatch($middleware, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('handler reached', (string) $response->getBody());
        self::assertSame([], $spy->calls);
    }

    // ── The disclosures that are declared, not leaked ────────────────────

    #[Test]
    public function aPublicRouteThatOptsOutDisclosesExistenceBecauseThatIsWhatItIs(): void
    {
        // #[PublicRoute] plus the opt-out is an unauthenticated read, declared
        // twice, in the route table and on the controller. The 404 is the point
        // of such a route, so it stays — recorded here so that turning it into
        // a leak requires editing a test that says why it is not one.
        $spy = new DisclosureResolverSpy();
        $handler = [DisclosurePublicController::class, 'show'];
        $middleware = $this->middleware($spy, BindingPreset::Banking);

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING, handler: $handler, attributes: ['_without_authorization' => true]));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT, handler: $handler, attributes: ['_without_authorization' => true]));

        self::assertSame(200, $onExisting->getStatusCode());
        self::assertSame(404, $onAbsent->getStatusCode());
        self::assertCount(2, $spy->calls);
    }

    #[Test]
    public function thePermissivePresetDisclosesExistenceAsItDocuments(): void
    {
        // BindingPreset::Standard hands the model to a caller it cannot
        // identify, by design and in writing. Existence follows from that, and
        // the fix for it is the preset, not the ordering.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware($spy, BindingPreset::Standard);

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT));

        self::assertSame(200, $onExisting->getStatusCode());
        self::assertSame(404, $onAbsent->getStatusCode());
        self::assertCount(2, $spy->calls);
    }

    #[Test]
    public function anUnentitledCallerCannotTellAnExistingIdFromAnAbsentOne(): void
    {
        // The half ordering cannot close, closed by the answer instead. A policy
        // answers "may this caller have THIS record", which cannot be known
        // before the record is read — so unlike the anonymous case the resolver
        // IS reached, for both ids. What must not differ is what comes back. It
        // used to: `403` when the record existed and `404` when it did not,
        // which is the anonymous case's oracle again, readable by anyone with an
        // account and no entitlement.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware(
            $spy,
            BindingPreset::Banking,
            fn(): IdentityInterface => $this->identity(),
            hookAllows: false,
        );

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT));

        self::assertSame(404, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertCount(2, $spy->calls, 'authorization needs the row, so the resolver is reached for both');
    }

    #[Test]
    public function theUnentitledJsonRefusalIsIdenticalForBothIdsToo(): void
    {
        // The negotiated body is the other place a refusal could differ, and it
        // is where a message naming the model class and the key it looked for
        // would land. Under this posture neither id gets one.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware(
            $spy,
            BindingPreset::Banking,
            fn(): IdentityInterface => $this->identity(),
            hookAllows: false,
        );

        $onExisting = $this->dispatch($middleware, $this->request(self::EXISTING, json: true));
        $onAbsent = $this->dispatch($middleware, $this->request(self::ABSENT, json: true));

        self::assertSame(404, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertStringNotContainsString(DisclosureRecord::class, (string) $onExisting->getBody());
    }

    #[Test]
    public function anUnentitledCallerLearnsNothingAboutAParentEither(): void
    {
        // The nested shape is where the old split disclosed the PARENT: the
        // record was fetched to scope the entry, the hook refused it, and the
        // caller got a `403` for a record that exists against a `404` for one
        // that does not — having no claim on either. The walk stops at the
        // parent in both cases, so the child is never looked up either way.
        $spy = new DisclosureResolverSpy();
        $middleware = $this->middleware(
            $spy,
            BindingPreset::Banking,
            fn(): IdentityInterface => $this->identity(),
            hookAllows: false,
        );

        $onExisting = $this->dispatch($middleware, $this->nestedRequest(self::EXISTING, '7'));
        $onAbsent = $this->dispatch($middleware, $this->nestedRequest(self::ABSENT, '7'));

        self::assertSame(404, $onExisting->getStatusCode());
        self::assertResponsesIndistinguishable($onExisting, $onAbsent);
        self::assertSame(
            [],
            array_values(array_filter(
                $spy->calls,
                static fn(array $call): bool => $call['method'] === 'resolveScoped',
            )),
            'a caller refused at the parent must not cause the child to be read',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private static function assertResponsesIndistinguishable(ResponseInterface $one, ResponseInterface $other): void
    {
        self::assertSame($one->getStatusCode(), $other->getStatusCode());
        self::assertSame($one->getReasonPhrase(), $other->getReasonPhrase());
        self::assertSame($one->getHeaders(), $other->getHeaders());
        self::assertSame((string) $one->getBody(), (string) $other->getBody());
    }

    /**
     * @param (Closure(): ?IdentityInterface)|null $identityResolver
     */
    private function middleware(
        DisclosureResolverSpy $spy,
        BindingPreset $preset,
        ?Closure $identityResolver = null,
        bool $hookAllows = true,
        ?CompiledBindingMap $map = null,
    ): ModelBindingMiddleware {
        $authHook = $this->createStub(AuthorizationHookInterface::class);
        $authHook->method('authorize')->willReturn($hookAllows);

        return new ModelBindingMiddleware(
            binder: new ModelBinder(
                defaultResolver: $spy,
                bindingResolver: new BindingResolver(compiledMap: $map),
                container: $this->createStub(ContainerInterface::class),
            ),
            config: new ModelBindingConfig(preset: $preset),
            authHook: $authHook,
            identityResolver: $identityResolver,
        );
    }

    /**
     * @param array{0: class-string, 1: string}|null $handler
     * @param array<string, mixed> $attributes
     */
    private function request(
        string $record,
        bool $json = false,
        ?array $handler = null,
        array $attributes = [],
    ): ServerRequestInterface {
        $route = new Route(
            methods: [Method::GET],
            path: '/records/{record}',
            handler: $handler ?? [DisclosureController::class, 'show'],
            name: 'records.show',
            attributes: $attributes,
        );

        $headers = $json ? ['Accept' => 'application/json'] : [];

        return new ServerRequest('GET', '/records/' . $record, $headers)
            ->withAttribute('_route', new MatchedRoute($route, ['record' => $record]));
    }

    private function nestedRequest(string $record, string $entry): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/records/{record}/entries/{entry}',
            handler: [DisclosureController::class, 'showEntry'],
            name: 'records.entries.show',
        );

        return new ServerRequest('GET', '/records/' . $record . '/entries/' . $entry)
            ->withAttribute('_route', new MatchedRoute($route, ['record' => $record, 'entry' => $entry]));
    }

    private function comparisonRequest(): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/compare/{left}/{right}',
            handler: [DisclosureController::class, 'compare'],
            name: 'records.compare',
        );

        return new ServerRequest('GET', '/compare/42/42')
            ->withAttribute('_route', new MatchedRoute($route, ['left' => self::EXISTING, 'right' => self::EXISTING]));
    }

    /**
     * Run the middleware the way the kernel runs it.
     *
     * The middleware does not read `_route`: the kernel passes the route it is
     * dispatching to the pipeline, which binds a per-dispatch copy of the
     * middleware to it — see
     * {@see \Pulsar\Http\Middleware\DispatchedRouteAwareInterface}. Here the
     * request builders write the same route into both places, which is what a
     * real dispatch does; that the two cannot be made to DISAGREE is the subject
     * of {@see DispatchedRouteAuthorityTest}, not of this file.
     */
    private function dispatch(ModelBindingMiddleware $middleware, ServerRequestInterface $request): ResponseInterface
    {
        /** @var MatchedRoute|null $route */
        $route = $request->getAttribute('_route');

        $bound = $route === null ? $middleware : $middleware->forDispatchedRoute($route);

        return $bound->process($request, $this->handler());
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'handler reached'));

        return $handler;
    }

    private function identity(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('caller-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }
}

/**
 * A resolver that records every call it receives and resolves exactly one id.
 *
 * The record of calls is the assertion this file is built on: a refusal decided
 * before any call is a refusal that cannot depend on the data, and a refusal
 * that costs the same query time as any other.
 *
 * @internal
 */
final class DisclosureResolverSpy implements ModelResolverPort
{
    /** The only id this spy resolves. Every other key is an absent row. */
    public const string RESOLVABLE_ID = '42';

    /** @var list<array{method: string, class: class-string, key: string|int}> */
    public array $calls = [];

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $this->calls[] = ['method' => 'resolve', 'class' => $modelClass, 'key' => $keyValue];

        return (string) $keyValue === self::RESOLVABLE_ID ? new DisclosureRecord() : null;
    }

    /**
     * Declared `object` rather than the port's `?object`: this spy always
     * resolves the scoped child, so a nullable return would advertise a branch
     * no test can reach. Narrowing a return type is covariant and legal, and it
     * keeps the double's signature an honest description of what it does.
     */
    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): object {
        $this->calls[] = ['method' => 'resolveScoped', 'class' => $modelClass, 'key' => $keyValue];

        return new DisclosureEntry();
    }
}

/** @internal */
final class DisclosureRecord {}

/** @internal */
final class DisclosureEntry {}

/** @internal */
final class DisclosureController
{
    public function show(DisclosureRecord $record): void {}

    public function showEntry(DisclosureRecord $record, DisclosureEntry $entry): void {}

    public function compare(DisclosureRecord $left, DisclosureRecord $right): void {}
}

/**
 * A controller declared public: the opt-out is legal on its routes.
 *
 * @internal
 */
#[PublicRoute(reason: 'Fixture for the unauthenticated-disclosure property')]
final class DisclosurePublicController
{
    public function show(DisclosureRecord $record): void {}
}

/**
 * A controller whose parameter names a route placeholder but no model.
 *
 * @internal
 */
final class DisclosureUnboundController
{
    public function ping(string $note): void {}
}
