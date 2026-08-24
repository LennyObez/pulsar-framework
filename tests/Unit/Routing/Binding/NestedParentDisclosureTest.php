<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Routing\Binding\BindingMeta;
use Pulsar\Routing\Binding\BindingPreset;
use Pulsar\Routing\Binding\BindingResolver;
use Pulsar\Routing\Binding\Contract\AuthorizationHookInterface;
use Pulsar\Routing\Binding\Contract\ModelResolverPort;
use Pulsar\Routing\Binding\ModelBinder;
use Pulsar\Routing\Binding\ModelBindingConfig;
use Pulsar\Routing\Binding\ModelBindingMiddleware;
use Pulsar\Routing\Binding\ResolutionContext;
use Pulsar\Routing\MatchedRoute;
use Pulsar\Routing\Route;

use function in_array;

/**
 * What a nested route lets a caller learn about the parent it is nested under.
 *
 * `/records/{record}/entries/{entry}` resolves outside-in: the record is
 * fetched to scope the entry, so it is read first and unconditionally. The
 * middleware used to authorize afterwards, over the finished map of resolved
 * models, and that one pass of distance was an existence oracle on the parent:
 *
 * - a caller with no claim on the record still caused it to be read, and the
 *   entry to be looked up inside it, and got back a `404` naming the ENTRY —
 *   an answer only a record that exists can produce;
 * - when the entry was absent the request threw from the child's lookup, before
 *   the authorization pass began, so the hook had run for NEITHER level even
 *   though the parent had been read;
 * - and the two bodies named two different model classes, so "no such record"
 *   and "no such entry under this record" were distinguishable without even
 *   reading the status code.
 *
 * The property here is the ordering, stated as what the caller can observe: a
 * level is decided before the next one is read, so nothing past a refusal is
 * ever touched, and every level that WAS read has been through the hook. The
 * event log both fixtures write to is what makes that assertable — resolver
 * calls and hook calls interleaved in the order they happened, which is the
 * only place the difference between "authorized as it resolves" and "authorized
 * afterwards" is visible at all.
 */
#[CoversClass(ModelBindingMiddleware::class)]
#[CoversClass(ModelBinder::class)]
final class NestedParentDisclosureTest extends TestCase
{
    private const string RECORD_ID = '4181';

    private const string ENTRY_ID = '7';

    // ── The ordering, as the caller can observe it ───────────────────────

    #[Test]
    public function everyLevelIsAuthorizedBeforeTheNextOneIsRead(): void
    {
        $log = new NestedEventLog();

        $response = $this->dispatch($this->middleware($log), $this->nestedRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'resolve ' . NestedRecord::class,
            'authorize ' . NestedRecord::class,
            'resolveScoped ' . NestedEntry::class,
            'authorize ' . NestedEntry::class,
        ], $log->events, 'the parent must be decided before the child is looked up');
    }

    #[Test]
    public function aCallerRefusedTheParentNeverCausesTheChildToBeLookedUp(): void
    {
        $log = new NestedEventLog();

        $response = $this->dispatch($this->middleware($log, denied: [NestedRecord::class]), $this->nestedRequest());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([
            'resolve ' . NestedRecord::class,
            'authorize ' . NestedRecord::class,
        ], $log->events);
    }

    #[Test]
    public function theChildsExistenceCannotBeProbedThroughAParentTheCallerMayNotSee(): void
    {
        // The disclosure property in the shape an attacker would use it: hold
        // the parent fixed at a record they are refused, and vary the child.
        // Both requests must be the same response, and neither may reach the
        // child's resolver — otherwise the entries under a record the caller
        // cannot open are enumerable one 404 at a time.
        $presentChild = new NestedEventLog();
        $absentChild = new NestedEventLog();

        $onPresent = $this->dispatch($this->middleware($presentChild, denied: [NestedRecord::class]), $this->nestedRequest());
        $onAbsent = $this->dispatch($this->middleware($absentChild, childExists: false, denied: [NestedRecord::class]), $this->nestedRequest());

        self::assertSame(404, $onPresent->getStatusCode());
        self::assertResponsesIndistinguishable($onPresent, $onAbsent);
        self::assertSame($presentChild->events, $absentChild->events);
        self::assertNotContains('resolveScoped ' . NestedEntry::class, $presentChild->events);
    }

    #[Test]
    public function theHookHasRunForEveryLevelThatWasRead(): void
    {
        // The child is absent, so the request ends in a 404 raised by the
        // child's lookup. The parent was still read to get there, and the whole
        // defect was that the hook never saw it: the throw came from inside the
        // binder, and the authorization pass that would have covered the parent
        // ran after the binder returned — which, on this path, it never did.
        $log = new NestedEventLog();

        $response = $this->dispatch($this->middleware($log, childExists: false), $this->nestedRequest());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([
            'resolve ' . NestedRecord::class,
            'authorize ' . NestedRecord::class,
            'resolveScoped ' . NestedEntry::class,
        ], $log->events);
    }

    // ── What the refusal itself says ─────────────────────────────────────

    #[Test]
    public function anAnonymousCallerCannotTellAnExistingParentFromAnAbsentOne(): void
    {
        $present = new NestedEventLog();
        $absent = new NestedEventLog();

        $onPresent = $this->dispatch($this->middleware($present, anonymous: true), $this->nestedRequest());
        $onAbsent = $this->dispatch($this->middleware($absent, parentExists: false, anonymous: true), $this->nestedRequest());

        self::assertSame(401, $onPresent->getStatusCode());
        self::assertResponsesIndistinguishable($onPresent, $onAbsent);
        self::assertSame([], $present->events);
        self::assertSame([], $absent->events);
    }

    #[Test]
    public function noRefusalNamesTheLevelItRefusedOrTheSegmentItReadItrom(): void
    {
        // Whatever the caller is told, they are not told which class the
        // framework was looking for or which segment it was looking it up by.
        // A 404 reading `No [Pulsar\Tests\…\NestedEntry] found for [id] = "7"`
        // answers both of those for an unauthenticated scanner, and echoes the
        // caller's own input back out of a body whose type the plain-text
        // branch did not even declare.
        foreach ([true, false] as $json) {
            $onDeniedParent = $this->dispatch($this->middleware(new NestedEventLog(), denied: [NestedRecord::class]), $this->nestedRequest(json: $json));
            $onAbsentChild = $this->dispatch($this->middleware(new NestedEventLog(), childExists: false), $this->nestedRequest(json: $json));

            foreach ([$onDeniedParent, $onAbsentChild] as $response) {
                $body = (string) $response->getBody();

                self::assertStringNotContainsString(NestedRecord::class, $body);
                self::assertStringNotContainsString(NestedEntry::class, $body);
                self::assertStringNotContainsString(self::RECORD_ID, $body);
                self::assertStringNotContainsString('entries', $body);
            }
        }
    }

    #[Test]
    public function theNestedRouteDisclosesNoMoreAboutTheParentThanTheParentsOwnRouteDoes(): void
    {
        // Four requests, one answer. A caller the hook refuses cannot tell a
        // real parent from an absent one — the refusal reads the row, so it
        // cannot be decided earlier, and it is therefore answered with the
        // status an absent row produces. And the nested route says exactly what
        // `/records/{record}` already says about the same record, so nesting
        // adds no channel of its own on top.
        $denied = [NestedRecord::class];

        $nestedOnPresent = $this->dispatch($this->middleware(new NestedEventLog(), denied: $denied), $this->nestedRequest());
        $nestedOnAbsent = $this->dispatch($this->middleware(new NestedEventLog(), parentExists: false, denied: $denied), $this->nestedRequest());

        $directOnPresent = $this->dispatch($this->middleware(new NestedEventLog(), denied: $denied), $this->recordRequest());
        $directOnAbsent = $this->dispatch($this->middleware(new NestedEventLog(), parentExists: false, denied: $denied), $this->recordRequest());

        self::assertSame(404, $nestedOnPresent->getStatusCode());
        self::assertResponsesIndistinguishable($nestedOnPresent, $nestedOnAbsent);
        self::assertResponsesIndistinguishable($nestedOnPresent, $directOnPresent);
        self::assertResponsesIndistinguishable($nestedOnAbsent, $directOnAbsent);
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
     * @param list<class-string> $denied Classes the hook refuses this caller
     */
    private function middleware(
        NestedEventLog $log,
        bool $parentExists = true,
        bool $childExists = true,
        array $denied = [],
        bool $anonymous = false,
    ): ModelBindingMiddleware {
        $identity = $anonymous ? null : $this->caller();

        return new ModelBindingMiddleware(
            binder: new ModelBinder(
                defaultResolver: new NestedResolverSpy($log, $parentExists, $childExists),
                bindingResolver: new BindingResolver(),
                container: $this->createStub(ContainerInterface::class),
            ),
            config: new ModelBindingConfig(preset: BindingPreset::Banking),
            authHook: new NestedRecordingHook($log, $denied),
            identityResolver: static fn(): ?IdentityInterface => $identity,
        );
    }

    private function nestedRequest(bool $json = false): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/records/{record}/entries/{entry}',
            handler: [NestedController::class, 'showEntry'],
            name: 'records.entries.show',
        );

        $path = '/records/' . self::RECORD_ID . '/entries/' . self::ENTRY_ID;

        return new ServerRequest('GET', $path, $json ? ['Accept' => 'application/json'] : [])
            ->withAttribute('_route', new MatchedRoute($route, [
                'record' => self::RECORD_ID,
                'entry' => self::ENTRY_ID,
            ]));
    }

    private function recordRequest(): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/records/{record}',
            handler: [NestedController::class, 'showRecord'],
            name: 'records.show',
        );

        return new ServerRequest('GET', '/records/' . self::RECORD_ID)
            ->withAttribute('_route', new MatchedRoute($route, ['record' => self::RECORD_ID]));
    }

    /**
     * Run the middleware the way the kernel runs it: bound to the route being
     * dispatched, which the pipeline hands it rather than the request.
     *
     * The request builders here write the same route into both places, which is
     * what a real dispatch does. That the two cannot be made to DISAGREE is the
     * subject of {@see DispatchedRouteAuthorityTest}.
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

    private function caller(): IdentityInterface
    {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('caller-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }
}

/**
 * Resolver calls and hook calls, in the order they actually happened.
 *
 * Sharing one log between the resolver and the hook is what makes the ordering
 * assertable: "authorized as it resolves" and "authorized afterwards" produce
 * the same set of calls and a different sequence.
 *
 * @internal
 */
final class NestedEventLog
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}

/**
 * A two-level resolver whose levels can be made to exist independently.
 *
 * @internal
 */
final readonly class NestedResolverSpy implements ModelResolverPort
{
    public function __construct(
        private NestedEventLog $log,
        private bool $parentExists = true,
        private bool $childExists = true,
    ) {}

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): ?object
    {
        $this->log->record('resolve ' . $modelClass);

        return $this->parentExists ? new NestedRecord() : null;
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
        $this->log->record('resolveScoped ' . $modelClass);

        return $this->childExists ? new NestedEntry() : null;
    }
}

/**
 * An authorization hook that records what it was asked about and refuses a
 * named set of classes.
 *
 * @internal
 */
final readonly class NestedRecordingHook implements AuthorizationHookInterface
{
    /**
     * @param list<class-string> $denied
     */
    public function __construct(
        private NestedEventLog $log,
        private array $denied = [],
    ) {}

    #[Override]
    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        $this->log->record('authorize ' . $model::class);

        return !in_array($model::class, $this->denied, true);
    }
}

/** @internal */
final class NestedRecord {}

/** @internal */
final class NestedEntry {}

/** @internal */
final class NestedController
{
    public function showRecord(NestedRecord $record): void {}

    public function showEntry(NestedRecord $record, NestedEntry $entry): void {}
}
