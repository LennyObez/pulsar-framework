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
use Psr\Log\AbstractLogger;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Method;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
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
use RuntimeException;
use Stringable;
use Throwable;

use function array_keys;
use function count;
use function in_array;

/**
 * An anonymous denial is counted. It is not written down.
 *
 * ## The rule, and the three reasons for it
 *
 * Closing the unauthenticated existence oracle moved the "no identity" check in
 * front of the resolver, and the audit call moved with it. That put a
 * tamper-evident chain write on the anonymous path of every bound route — and
 * under a regulated preset every bound route refuses an anonymous request. One
 * request with no credentials became one HMAC chain advance and one `LOCK_EX`
 * append.
 *
 * The repair that followed was a per-process ledger with a capacity, and it was
 * worse than the defect: past 64 shapes it refused every shape, so an attacker
 * who filled it silenced the denials that name somebody. A ceiling that can be
 * filled is an audit-suppression switch whatever its capacity. It is gone, and
 * nothing like it replaces it — the split is drawn at ATTRIBUTION instead, where
 * neither half needs a ceiling:
 *
 * - A denial of an IDENTIFIED caller is written in full, every time, with
 *   nothing in front of it. Its volume is bounded by the number of credentials
 *   that exist, and whoever floods it is named in every line they add.
 * - A denial with NO identity is counted through the metrics registry and
 *   touches neither the chain nor the filesystem.
 *
 * Not because the chain cannot hold an anonymous actor — {@see AuditActor}
 * ships `anonymous()` for exactly that, and the framework uses it elsewhere.
 * Three other things decide it:
 *
 * 1. **Nothing was accessed.** The refusal is reached before any resolver runs,
 *    so the record would be evidence of no access at all. That is measured
 *    below, not asserted rhetorically.
 * 2. **The key space would belong to the caller.** With no ceiling, an entry per
 *    request lets an unauthenticated caller decide how far the chain grows.
 * 3. **Every record would be the same record.** Same actor, same action, same
 *    reason, same models, differing only in a path the caller chose.
 *
 * ## The same defect, one layer over
 *
 * A 500 out of {@see \Pulsar\Routing\Binding\ModelBinder::plan()} is a permanent
 * property of the route — the refusal is read off declarations and memoised —
 * and the middleware used to log it, with a stack trace, on every request that
 * hit the route. The diagnosis is now published once by
 * {@see BindingResolver::resolveForRoute()}, which is where it is decided, and
 * the requests are counted. That is an aggregation and not a ceiling: there is
 * no capacity to fill, and no number of broken routes can stop the next one
 * being reported.
 */
#[CoversClass(ModelBindingMiddleware::class)]
#[CoversClass(BindingResolver::class)]
final class AnonymousDenialAuditTest extends TestCase
{
    private ?RequestHandlerInterface $handler = null;

    #[Test]
    public function anAnonymousFloodWritesNothingToTheChainAndIsCountedInFull(): void
    {
        // The property, stated as bluntly as it can be. Every request is
        // refused, every request is counted, every request is logged, and the
        // chain does not move.
        $audit = new CountingAuditLogger();
        $log = new CountingLogger();
        $metrics = new MetricRegistry();
        $middleware = $this->middleware($audit, $log, anonymous: true, metrics: $metrics);

        for ($i = 0; $i < 500; $i++) {
            $response = $this->dispatch($middleware, $this->route(), '/audited/' . $i);

            self::assertSame(401, $response->getStatusCode());
        }

        self::assertSame([], $audit->entries);
        self::assertSame(500, $log->debugCount);
        self::assertSame(
            500.0,
            $metrics->counter('pulsar_model_binding_anonymous_denials_total')
                ->value(self::labels(['reason' => 'unauthenticated', 'route' => 'audited.show'])),
        );
    }

    #[Test]
    public function theAuditLoggerIsNeverEvenCalledOnTheAnonymousPath(): void
    {
        // Stronger than counting entries, and the one that matters for cost: an
        // audit logger that throws on every call is not reached at all, so the
        // anonymous path costs no HMAC advance, no `LOCK_EX` append and no
        // failure handling either.
        $audit = new FailingAuditLogger(new RuntimeException('the sink must not be touched'));
        $log = new CountingLogger();
        $middleware = $this->middleware($audit, $log, anonymous: true);

        for ($i = 0; $i < 200; $i++) {
            self::assertSame(401, $this->dispatch($middleware, $this->route(), '/audited/' . $i)->getStatusCode());
        }

        self::assertSame(0, $audit->attempts);
        self::assertSame(0, $log->criticalCount);
    }

    #[Test]
    public function noResolverRunsForAnAnonymousCallerWhateverIdIsAsked(): void
    {
        // The first of the three reasons, measured. The refusal is decided from
        // the route and the caller, so an id that exists and an id that does not
        // are the same instructions and the same work — there is no row read to
        // record, and no timing difference to read off either.
        $resolver = new AnonymousDenialResolver();
        $middleware = $this->middleware(new CountingAuditLogger(), anonymous: true, resolver: $resolver);

        for ($i = 0; $i < 200; $i++) {
            $this->dispatch($middleware, $this->route(), '/audited/' . $i);
        }

        self::assertSame(0, $resolver->calls);
    }

    #[Test]
    public function theCountNamesTheRouteAndTheReasonAndNothingTheCallerChose(): void
    {
        // A Counter keys a map in memory on its label set, so a label taken from
        // the request would rebuild inside the process exactly the unbounded key
        // space the chain write was removed for. Both labels come from the route
        // table: three different paths are three increments of one series.
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(new CountingAuditLogger(), anonymous: true, metrics: $metrics);

        foreach (['/audited/1', '/audited/4181', '/audited/%2e%2e'] as $path) {
            $this->dispatch($middleware, $this->route(), $path);
        }

        $values = $metrics->counter('pulsar_model_binding_anonymous_denials_total')->values();

        self::assertSame(['reason=unauthenticated,route=audited.show'], array_keys($values));
        self::assertSame(3.0, $values['reason=unauthenticated,route=audited.show']);
    }

    #[Test]
    public function eachRouteAndReasonGetsItsOwnSeries(): void
    {
        // Aggregation, not erasure. An assessor asking "what did THIS route
        // refuse" still finds this route, and a deployment refusing on two
        // grounds still shows both.
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(new CountingAuditLogger(), anonymous: true, metrics: $metrics);

        $this->dispatch($middleware, $this->route(), '/audited/1');
        $this->dispatch($middleware, $this->route('audited.other', '/other/{record}'), '/other/1');
        $this->dispatch($middleware, $this->route(attributes: ['_without_authorization' => true]), '/audited/1');

        self::assertSame(
            [
                'reason=unauthenticated,route=audited.show',
                'reason=unauthenticated,route=audited.other',
                'reason=authorization_bypass_forbidden,route=audited.show',
            ],
            array_keys($metrics->counter('pulsar_model_binding_anonymous_denials_total')->values()),
        );
    }

    #[Test]
    public function noNumberOfShapesEverSilencesTheNextOne(): void
    {
        // The property the deleted ledger did not have, asserted directly. Its
        // capacity was 64, and past it `claim()` returned false for EVERY shape:
        // an anonymous caller who could reach 64 route shapes silenced every
        // denial after them, legitimate ones included. There is no capacity here
        // to reach, so the four-hundredth shape is recorded exactly like the
        // first.
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(new CountingAuditLogger(), anonymous: true, metrics: $metrics);

        for ($i = 0; $i < 400; $i++) {
            $this->dispatch($middleware, $this->route('route.' . $i), '/audited/1');
        }

        $values = $metrics->counter('pulsar_model_binding_anonymous_denials_total')->values();

        self::assertCount(400, $values);
        self::assertSame(1.0, $values['reason=unauthenticated,route=route.399']);
    }

    #[Test]
    public function bothSeriesExistAtZeroBeforeAnythingIsRefused(): void
    {
        // A dashboard has to be able to tell "no anonymous denials" from "this
        // deployment is not reporting", and an instrument that springs into
        // existence on first use cannot say the difference. Both are registered
        // when the middleware is built.
        $metrics = new MetricRegistry();
        $this->middleware(new CountingAuditLogger(), metrics: $metrics);

        self::assertTrue($metrics->has('pulsar_model_binding_anonymous_denials_total'));
        self::assertTrue($metrics->has('pulsar_model_binding_refusals_total'));
    }

    #[Test]
    public function theCountSurvivesTheCloneEveryDispatchMakes(): void
    {
        // The middleware is cloned per dispatch, so the route it is bound to
        // cannot be seen by a concurrently suspended request. An array property
        // would be copied by that clone and every request would start from
        // scratch. The counters are objects owned by the registry, so the clone
        // copies a reference and two dispatches increment one series.
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(new CountingAuditLogger(), anonymous: true, metrics: $metrics);

        $first = $middleware->forDispatchedRoute(new MatchedRoute($this->route(), ['record' => '1']));
        $second = $middleware->forDispatchedRoute(new MatchedRoute($this->route(), ['record' => '2']));

        $first->process(new ServerRequest('GET', '/audited/1'), $this->handler());
        $second->process(new ServerRequest('GET', '/audited/2'), $this->handler());

        self::assertSame(
            2.0,
            $metrics->counter('pulsar_model_binding_anonymous_denials_total')
                ->value(self::labels(['reason' => 'unauthenticated', 'route' => 'audited.show'])),
        );
    }

    #[Test]
    public function metricsBeingOffCostsTheCountAndNothingElse(): void
    {
        // `observability.metrics.enabled` is a posture decision, and it must not
        // become a security one. With no registry the refusal is unchanged, the
        // chain is still untouched, and the `debug` line still carries every
        // occurrence. ModelBindingWiring declares the missing registry as a
        // degraded feature so the deployment is told, rather than discovering it
        // when somebody asks for the number.
        $audit = new CountingAuditLogger();
        $log = new CountingLogger();
        $middleware = $this->middleware($audit, $log, anonymous: true, metrics: null);

        for ($i = 0; $i < 50; $i++) {
            self::assertSame(401, $this->dispatch($middleware, $this->route(), '/audited/' . $i)->getStatusCode());
        }

        self::assertSame([], $audit->entries);
        self::assertSame(50, $log->debugCount);
    }

    #[Test]
    public function anIdentifiedCallerIsNotThrottled(): void
    {
        // The other half of the rule, and what makes it a rule about attribution
        // rather than a blanket limit on the audit chain. Every one of these
        // denials names a subject who can be revoked, so there is no flood to
        // bound — and compressing them WOULD lose evidence, because each is a
        // different caller-and-record pair.
        $audit = new CountingAuditLogger();
        $middleware = $this->middleware($audit, denied: [AnonymousDenialRecord::class]);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->dispatch($middleware, $this->route(), '/audited/' . $i);

            self::assertSame(404, $response->getStatusCode());
        }

        self::assertCount(20, $audit->entries);
        self::assertSame('caller-1', $audit->entries[19]->actor);
        self::assertSame('/audited/19', $audit->entries[19]->resource);
    }

    #[Test]
    public function anIdentifiedDenialIsBothRecordedAndCounted(): void
    {
        // The counter is not the anonymous path's consolation prize; it is the
        // volume series for every refusal this middleware serves. An identified
        // denial appears in both places, and the two answer different questions:
        // the chain says who was refused what, the counter says how often the
        // route is refusing.
        $audit = new CountingAuditLogger();
        $metrics = new MetricRegistry();
        $middleware = $this->middleware($audit, denied: [AnonymousDenialRecord::class], metrics: $metrics);

        for ($i = 0; $i < 7; $i++) {
            $this->dispatch($middleware, $this->route(), '/audited/' . $i);
        }

        self::assertCount(7, $audit->entries);
        self::assertSame(
            7.0,
            $metrics->counter('pulsar_model_binding_refusals_total')
                ->value(self::labels(['route' => 'audited.show', 'status' => '404'])),
        );
    }

    #[Test]
    public function aFailedAuditWriteStillDeniesTheRequest(): void
    {
        // The failures the old catch named were the ones raised while BUILDING
        // an entry. Writing one throws a SecurityException, and resolving an
        // actor throws an AuditActorMissingException, and neither was caught —
        // so a full audit volume turned every denial into an unaudited 500. A
        // failure to record must not become a failure to deny.
        foreach ([new RuntimeException('sink is full'), new JsonException('cannot encode')] as $failure) {
            $middleware = $this->middleware(
                new FailingAuditLogger($failure),
                new CountingLogger(),
                denied: [AnonymousDenialRecord::class],
            );

            $response = $this->dispatch($middleware, $this->route(), '/audited/4181');

            self::assertSame(404, $response->getStatusCode());
        }
    }

    #[Test]
    public function aFailedAuditWriteWithNoLoggerWiredStillReachesTheServerErrorLog(): void
    {
        // "Must not vanish" cannot depend on a logger being wired, because the
        // deployments most likely to lose an audit write are the least likely to
        // have finished wiring. With nowhere else to go the failure takes the
        // same last-resort channel the kernel uses for failures that happen
        // where no logging exists yet.
        //
        // The line carries the reason and the exception and NOT the actor: the
        // server error log has no redaction, and a subject identifier does not
        // belong in it.
        $this->expectOutputRegex('/Audit write failed for a model-binding denial \(reason=policy_denied\)/');

        $middleware = $this->middleware(
            new FailingAuditLogger(new RuntimeException('sink is full')),
            denied: [AnonymousDenialRecord::class],
        );

        self::assertSame(
            404,
            $this->dispatch($middleware, $this->route(), '/audited/4181')->getStatusCode(),
        );
    }

    #[Test]
    public function aFailedAuditWriteIsReportedAtCritical(): void
    {
        // ...and it must not vanish. The old catch was empty, so an audit
        // subsystem failing on every single denial produced no signal anywhere:
        // the deployment looked healthy and was recording nothing.
        $log = new CountingLogger();
        $middleware = $this->middleware(
            new FailingAuditLogger(new RuntimeException('sink is full')),
            $log,
            denied: [AnonymousDenialRecord::class],
        );

        $this->dispatch($middleware, $this->route(), '/audited/4181');

        self::assertSame(1, $log->criticalCount);
    }

    #[Test]
    public function aFailingChainIsAttemptedForEveryIdentifiedDenialBecauseNoneMayBeDropped(): void
    {
        // This test used to assert the opposite, and the thing it asserted was
        // the ledger: a shape was claimed BEFORE the write, so a permanently
        // failing sink was retried once and then never again. That is an
        // evidentiary guarantee being traded for a write count, and it is what a
        // ceiling always ends up buying.
        //
        // An identified denial is never skipped. Twenty denials are twenty
        // attempts and twenty `critical` lines, and the volume is bounded the
        // way the denial itself is: by the credentials that exist.
        $audit = new FailingAuditLogger(new RuntimeException('sink is full'));
        $log = new CountingLogger();
        $middleware = $this->middleware($audit, $log, denied: [AnonymousDenialRecord::class]);

        for ($i = 0; $i < 20; $i++) {
            $this->dispatch($middleware, $this->route(), '/audited/' . $i);
        }

        self::assertSame(20, $audit->attempts);
        self::assertSame(20, $log->criticalCount);
    }

    #[Test]
    public function aPermanentRouteDefectIsAnnouncedOnceAndCountedEveryTime(): void
    {
        // Blocker 6: the same write amplification, one layer over. A route whose
        // handler hints `A|B` for one route parameter can never be served for
        // anybody; the refusal is decided from declarations, memoised, and
        // rethrown unchanged. Logging it per request wrote the identical message
        // and stack trace again and again — measured at 1,761 bytes and 853 µs
        // per request, on a path an unauthenticated caller can reach.
        //
        // The diagnosis now comes from the decider, once. The occurrences are
        // counted.
        $log = new CountingLogger();
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(
            new CountingAuditLogger(),
            $log,
            preset: BindingPreset::Standard,
            metrics: $metrics,
            resolverLogger: $log,
        );

        for ($i = 0; $i < 300; $i++) {
            $response = $this->dispatch($middleware, $this->ambiguousRoute(), '/ambiguous/' . $i);

            self::assertSame(500, $response->getStatusCode());
        }

        self::assertSame(1, $log->errorCount);
        self::assertSame(
            300.0,
            $metrics->counter('pulsar_model_binding_refusals_total')
                ->value(self::labels(['route' => 'ambiguous.show', 'status' => '500'])),
        );
    }

    #[Test]
    public function everyBrokenRouteIsAnnouncedNoMatterHowManyCameBefore(): void
    {
        // The anti-ceiling property for blocker 6, and the reason the fix is an
        // aggregation rather than a rate limit. There is no capacity and no
        // claim: the deduplication IS the resolver's memo, which must already
        // hold this route shape for the replay to happen at all. Two hundred
        // broken routes are two hundred diagnoses.
        $log = new CountingLogger();
        $middleware = $this->middleware(
            new CountingAuditLogger(),
            $log,
            preset: BindingPreset::Standard,
            resolverLogger: $log,
        );

        for ($i = 0; $i < 200; $i++) {
            $this->dispatch($middleware, $this->ambiguousRoute('ambiguous.' . $i), '/ambiguous/1');
        }

        self::assertSame(200, $log->errorCount);
    }

    #[Test]
    public function aBrokenRouteIsStillRefusedIdenticallyWhenNobodyIsListening(): void
    {
        // The report is a diagnostic and the refusal is the behaviour. With no
        // logger anywhere the route is refused exactly as before — 500, the
        // reason phrase and nothing else — and the count still happens.
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(
            new CountingAuditLogger(),
            preset: BindingPreset::Standard,
            metrics: $metrics,
        );

        for ($i = 0; $i < 10; $i++) {
            self::assertSame(500, $this->dispatch($middleware, $this->ambiguousRoute(), '/ambiguous/1')->getStatusCode());
        }

        self::assertSame(
            10.0,
            $metrics->counter('pulsar_model_binding_refusals_total')
                ->value(self::labels(['route' => 'ambiguous.show', 'status' => '500'])),
        );
    }

    #[Test]
    public function aBrokenRouteIsDiagnosedOnceEvenWhenEveryCallerIsRefusedBeforeSeeingIt(): void
    {
        // Two properties at once, and they have to hold together.
        //
        // The caller's: under a regulated preset an anonymous request to a
        // MISDECLARED route gets the same 401 as one to a well-formed route.
        // plan() is computed first precisely so that it can, and the refusal is
        // then decided from the route and the caller alone — so the 500 is not
        // an oracle for "this route is misconfigured" any more than it is one
        // for "this id exists".
        //
        // The operator's: the route is broken whoever knocked on it, so the
        // diagnosis is still written. Once, by the decider, however many
        // anonymous requests arrive — and the requests land on the anonymous
        // series, not on a stack trace apiece.
        $log = new CountingLogger();
        $metrics = new MetricRegistry();
        $middleware = $this->middleware(
            new CountingAuditLogger(),
            $log,
            anonymous: true,
            metrics: $metrics,
            resolverLogger: $log,
        );

        for ($i = 0; $i < 200; $i++) {
            self::assertSame(401, $this->dispatch($middleware, $this->ambiguousRoute(), '/ambiguous/' . $i)->getStatusCode());
        }

        self::assertSame(1, $log->errorCount);
        self::assertSame(
            200.0,
            $metrics->counter('pulsar_model_binding_anonymous_denials_total')
                ->value(self::labels(['reason' => 'unauthenticated', 'route' => 'ambiguous.show'])),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $labels
     */
    private static function labels(array $labels): LabelSet
    {
        return new LabelSet($labels);
    }

    /**
     * @param list<class-string> $denied
     */
    private function middleware(
        AuditLoggerInterface $auditLogger,
        ?CountingLogger $logger = null,
        bool $anonymous = false,
        array $denied = [],
        ?MetricRegistry $metrics = null,
        BindingPreset $preset = BindingPreset::Healthcare,
        ?AnonymousDenialResolver $resolver = null,
        ?CountingLogger $resolverLogger = null,
    ): ModelBindingMiddleware {
        $identity = $anonymous ? null : $this->caller();

        return new ModelBindingMiddleware(
            binder: new ModelBinder(
                defaultResolver: $resolver ?? new AnonymousDenialResolver(),
                bindingResolver: new BindingResolver(logger: $resolverLogger),
                container: $this->createStub(ContainerInterface::class),
            ),
            config: new ModelBindingConfig(preset: $preset),
            authHook: new AnonymousDenialHook($denied),
            identityResolver: static fn(): ?IdentityInterface => $identity,
            logger: $logger,
            auditLogger: $auditLogger,
            metrics: $metrics,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function route(
        string $name = 'audited.show',
        string $path = '/audited/{record}',
        array $attributes = [],
    ): Route {
        return new Route(
            methods: [Method::GET],
            path: $path,
            handler: [AnonymousDenialController::class, 'show'],
            name: $name,
            attributes: $attributes,
        );
    }

    /**
     * A route the binding layer can never serve: the handler hints two bindable
     * classes for one route parameter, which reflection cannot settle and no
     * explicit binding here answers.
     */
    private function ambiguousRoute(string $name = 'ambiguous.show'): Route
    {
        return new Route(
            methods: [Method::GET],
            path: '/ambiguous/{record}',
            handler: [AnonymousDenialAmbiguousController::class, 'show'],
            name: $name,
        );
    }

    private function dispatch(ModelBindingMiddleware $middleware, Route $route, string $path): ResponseInterface
    {
        return $middleware
            ->forDispatchedRoute(new MatchedRoute($route, ['record' => '4181']))
            ->process(new ServerRequest('GET', $path), $this->handler());
    }

    /**
     * One handler, not one per dispatch. These tests send hundreds of requests
     * each on purpose - a bound that only shows up under volume cannot be
     * asserted from a single request - and a PHPUnit double built per dispatch
     * turns that into minutes of test-double construction.
     */
    private function handler(): RequestHandlerInterface
    {
        return $this->handler ??= new AnonymousDenialHandler();
    }

    private function caller(): IdentityInterface
    {
        // Built once per middleware rather than once per dispatch, so a double
        // is cheap here in a way it is not on the request path above.
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('id')->willReturn('caller-1');
        $identity->method('isAuthenticated')->willReturn(true);

        return $identity;
    }
}

/**
 * Keeps every entry, so a test can count the chain.
 *
 * @internal
 */
final class CountingAuditLogger implements AuditLoggerInterface
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
 * Fails the way the chain really can, and counts how often it was asked to.
 *
 * @internal
 */
final class FailingAuditLogger implements AuditLoggerInterface
{
    public int $attempts = 0;

    public function __construct(private readonly Throwable $failure) {}

    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $this->attempts++;

        throw $this->failure;
    }
}

/**
 * Counts lines by level, which is the whole of what these tests ask of a logger.
 *
 * @internal
 */
final class CountingLogger extends AbstractLogger
{
    public int $debugCount = 0;
    public int $criticalCount = 0;
    public int $errorCount = 0;

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'debug') {
            $this->debugCount++;
        }

        if ($level === 'critical') {
            $this->criticalCount++;
        }

        if ($level === 'error') {
            $this->errorCount++;
        }
    }
}

/** @internal */
final class AnonymousDenialResolver implements ModelResolverPort
{
    public int $calls = 0;

    #[Override]
    public function resolve(string $modelClass, string $keyName, string|int $keyValue, ResolutionContext $context): object
    {
        $this->calls++;

        return new AnonymousDenialRecord();
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
        $this->calls++;

        return new AnonymousDenialRecord();
    }
}

/** @internal */
final readonly class AnonymousDenialHook implements AuthorizationHookInterface
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
final class AnonymousDenialHandler implements RequestHandlerInterface
{
    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(statusCode: 200, body: 'handler reached');
    }
}

/** @internal */
final class AnonymousDenialRecord {}

/** @internal */
final class AnonymousDenialOtherRecord {}

/** @internal */
final class AnonymousDenialController
{
    public function show(AnonymousDenialRecord $record): void {}
}

/** @internal */
final class AnonymousDenialAmbiguousController
{
    public function show(AnonymousDenialRecord|AnonymousDenialOtherRecord $record): void {}
}
