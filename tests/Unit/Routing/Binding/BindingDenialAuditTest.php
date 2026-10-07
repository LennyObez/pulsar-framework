<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Routing\Binding;

use DateTimeImmutable;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
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
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;
use function in_array;

/**
 * Every refusal that names somebody leaves a record in the chain.
 *
 * This framework's audit story is a tamper-evident chain, and the question an
 * assessor asks of it first is not "what was allowed" but "show me what was
 * refused". Three refusals can come out of this middleware — the missing
 * identity under a regulated preset, the illegal `_without_authorization`
 * opt-out, and the authorization hook's own `false` — and until recently
 * exactly one of them was written down.
 *
 * The other two had been moved: they were decided after binding, next to the
 * hook and next to the single audit call, and closing the unauthenticated
 * existence oracle moved them in front of the resolver while the audit call
 * stayed where it was. Nothing looked broken, because a denial that leaves no
 * trace produces the same HTTP response as one that does — the gap is only
 * visible from the audit store, months later, when someone asks who was turned
 * away from a patient record and the answer is silence.
 *
 * So the property is counted, not sampled, for every refusal of an IDENTIFIED
 * caller: one entry per occurrence, with the actor, the resource and the reason
 * that tell it from the other two, and a request that is NOT refused produces
 * none.
 *
 * A refusal decided with NO identity produces none either, and that is the
 * deliberate other half of the same rule: nothing was accessed, every such
 * record would be the same record, and an entry per request would let an
 * unauthenticated caller decide how far the chain grows. Those denials are
 * counted through the metrics registry instead — see
 * {@see AnonymousDenialAuditTest} for the whole argument and for the ceiling
 * that had to be deleted to reach it.
 */
#[CoversClass(ModelBindingMiddleware::class)]
final class BindingDenialAuditTest extends TestCase
{
    #[Test]
    public function theMissingIdentityRefusesAndWritesNothingToTheChain(): void
    {
        // The refusal is unchanged and the chain does not move. There is no
        // actor to name, nothing was read, and every entry this would write is
        // the same entry — so the volume goes to a counter and the evidentiary
        // log keeps only the refusals an assessor can act on.
        $audit = new RecordingAuditLogger();

        $response = $this->dispatch($this->middleware($audit, anonymous: true), $this->request());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $audit->entries);
    }

    #[Test]
    public function theIllegalOptOutIsRecorded(): void
    {
        // `_without_authorization` on a route that is not declared public is
        // forbidden under a regulated preset. It is the refusal most likely to
        // be a mistake rather than an attack, which is exactly why it has to be
        // findable: the route name is in the record, so the misdeclared route
        // can be named without reproducing the request.
        $audit = new RecordingAuditLogger();

        $response = $this->dispatch($this->middleware($audit), $this->request(attributes: ['_without_authorization' => true]));

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $audit->entries);

        $entry = $audit->entries[0];
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame('caller-1', $entry->actor);
        self::assertSame('authorization_bypass_forbidden', $entry->metadata['reason']);
        self::assertSame('audited.show', $entry->metadata['route']);
    }

    #[Test]
    public function theIllegalOptOutSplitsByWhoAsked(): void
    {
        // `authorization_bypass_forbidden` is the one reason that can be reached
        // by either kind of caller, so it is where the rule is visible in a
        // single test: the identified caller is in the chain by name, the
        // anonymous one is not in it at all, and both are refused `403`.
        $identified = new RecordingAuditLogger();
        $anonymous = new RecordingAuditLogger();
        $attributes = ['_without_authorization' => true];

        $first = $this->dispatch($this->middleware($identified), $this->request(attributes: $attributes));
        $second = $this->dispatch($this->middleware($anonymous, anonymous: true), $this->request(attributes: $attributes));

        self::assertSame(403, $first->getStatusCode());
        self::assertSame(403, $second->getStatusCode());

        self::assertCount(1, $identified->entries);
        self::assertSame('caller-1', $identified->entries[0]->actor);
        self::assertSame('authorization_bypass_forbidden', $identified->entries[0]->metadata['reason']);

        self::assertSame([], $anonymous->entries);
    }

    #[Test]
    public function theHookDenialIsRecordedWithTheLevelItRefused(): void
    {
        // A nested route can be refused at either level, and "which one" is the
        // whole of what an investigator needs: the parameter name and the model
        // class say whether the caller was turned away from the record or from
        // the entry inside it.
        $audit = new RecordingAuditLogger();

        $response = $this->dispatch($this->middleware($audit, denied: [AuditedEntry::class]), $this->nestedRequest());

        // The caller is told what a caller asking for a row that is not there is
        // told; the audit record is where the refusal keeps its detail. That
        // split is the point of recording it: the status can say nothing without
        // the denial becoming invisible.
        self::assertSame(404, $response->getStatusCode());
        self::assertCount(1, $audit->entries);

        $entry = $audit->entries[0];
        self::assertSame('policy_denied', $entry->metadata['reason']);
        self::assertSame(AuditedEntry::class, $entry->metadata['model']);
        self::assertSame('entry', $entry->metadata['parameter']);
        self::assertSame('/audited/4181/entries/7', $entry->resource);
    }

    #[Test]
    public function aRequestThatIsNotRefusedRecordsNothing(): void
    {
        // The counterpart property. An audit store that also fills up with
        // allowed requests answers "show me the denials" with a filter, and a
        // filter is a thing that can be wrong.
        $audit = new RecordingAuditLogger();

        $response = $this->dispatch($this->middleware($audit), $this->nestedRequest());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $audit->entries);
    }

    #[Test]
    public function aRefusalThatCannotBeRecordedIsStillARefusal(): void
    {
        // The audit chain is HMAC-backed and can fail on its own terms. It must
        // not turn a denial into a pass, and it must not turn one into a 500
        // either: the caller is refused exactly as they would have been. Nor may
        // the failure vanish — with no logger wired it takes the server error
        // log, the last-resort channel the kernel uses for the same reason.
        $this->expectOutputRegex('/Audit write failed for a model-binding denial/');

        $middleware = $this->middleware(new ThrowingAuditLogger(), denied: [AuditedRecord::class]);

        self::assertSame(404, $this->dispatch($middleware, $this->request())->getStatusCode());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param list<class-string> $denied
     */
    private function middleware(
        AuditLoggerInterface $auditLogger,
        bool $anonymous = false,
        array $denied = [],
    ): ModelBindingMiddleware {
        $identity = $anonymous ? null : $this->caller();

        return new ModelBindingMiddleware(
            binder: new ModelBinder(
                defaultResolver: new AuditedResolver(),
                bindingResolver: new BindingResolver(),
                container: $this->createStub(ContainerInterface::class),
            ),
            config: new ModelBindingConfig(preset: BindingPreset::Healthcare),
            authHook: new AuditedHook($denied),
            identityResolver: static fn(): ?IdentityInterface => $identity,
            auditLogger: $auditLogger,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function request(array $attributes = []): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/audited/{record}',
            handler: [AuditedController::class, 'show'],
            name: 'audited.show',
            attributes: $attributes,
        );

        return new ServerRequest('GET', '/audited/4181')
            ->withAttribute('_route', new MatchedRoute($route, ['record' => '4181']));
    }

    private function nestedRequest(): ServerRequestInterface
    {
        $route = new Route(
            methods: [Method::GET],
            path: '/audited/{record}/entries/{entry}',
            handler: [AuditedController::class, 'showEntry'],
            name: 'audited.entries.show',
        );

        return new ServerRequest('GET', '/audited/4181/entries/7')
            ->withAttribute('_route', new MatchedRoute($route, ['record' => '4181', 'entry' => '7']));
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
 * An audit logger that keeps what it was told, so a test can count it.
 *
 * @internal
 */
final class RecordingAuditLogger implements AuditLoggerInterface
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $entry = new AuditEntry(
            id: 'audit-' . count($this->entries),
            event: $event,
            outcome: $outcome,
            actor: match (true) {
                $actor instanceof AuditActor => $actor->id,
                $actor === null => '',
                default => $actor,
            },
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable(),
            metadata: $metadata,
            previousHmac: '',
            hmac: '',
        );

        $this->entries[] = $entry;

        return $entry;
    }
}

/**
 * An audit logger that fails the way the real chain can: mid-write.
 *
 * @internal
 */
final readonly class ThrowingAuditLogger implements AuditLoggerInterface
{
    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        throw new JsonException('audit chain write failed');
    }
}

/** @internal */
final readonly class AuditedResolver implements ModelResolverPort
{
    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): object
    {
        return new AuditedRecord();
    }

    #[Override]
    public function resolveScoped(
        string $modelClass,
        string $keyName,
        string|int $keyValue,
        object $parent,
        string $relation,
        ResolutionContext $context,
    ): object {
        return new AuditedEntry();
    }
}

/** @internal */
final readonly class AuditedHook implements AuthorizationHookInterface
{
    /**
     * @param list<class-string> $denied
     */
    public function __construct(private array $denied = []) {}

    #[Override]
    public function authorize(IdentityInterface $identity, object $model, BindingMeta $meta): bool
    {
        return !in_array($model::class, $this->denied, true);
    }
}

/** @internal */
final class AuditedRecord {}

/** @internal */
final class AuditedEntry {}

/** @internal */
final class AuditedController
{
    public function show(AuditedRecord $record): void {}

    public function showEntry(AuditedRecord $record, AuditedEntry $entry): void {}
}
