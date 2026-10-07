<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Core\Wiring;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\AuthorizationDecision;
use Pulsar\Auth\Authorization\AuthorizationDecisionSinkInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Internal\Authorization\AuthorizationDecisionFlushListener;
use Pulsar\Auth\Internal\Authorization\BufferedAuthorizationDecisionSink;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\StormProtectionConfig;
use Pulsar\Container\Container;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Core\Event\TerminateEvent;
use Pulsar\Core\Wiring\AuthWiring;
use Pulsar\Core\Wiring\EventWiring;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\Internal\CompiledListenerProvider;
use Pulsar\Event\Internal\EventDispatcher;
use Pulsar\Event\Internal\EventMapCompiler;
use Pulsar\Event\Internal\ListenerProvider;
use Pulsar\Event\Internal\StormGuard;
use Pulsar\Event\ListenerProviderInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Queue\Event\JobCompleted;
use Pulsar\Queue\Event\JobFailed;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Tests\Benchmark\Support\InMemoryAuditSink;
use RuntimeException;

use function bin2hex;
use function count;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * The Gate records nothing unless the composition root gives it a sink that
 * writes what it is handed into the audit chain. Both halves were missing once,
 * so every grant and every denial the framework made was invisible to the audit
 * chain the compliance story rests on.
 *
 * These tests assert the effect — an entry in the sink, chained and verifiable —
 * rather than the wiring. Asserting that a collaborator was passed would still
 * have passed with nothing writing anything, which is the failure mode being
 * fixed.
 *
 * They also assert what the sink is *not*: the decision must not travel through
 * the application's event dispatcher, because that put every listener the
 * application had registered inside every authorization decision.
 */
#[CoversClass(AuthWiring::class)]
#[CoversClass(BufferedAuthorizationDecisionSink::class)]
final class AuthorizationDecisionAuditWiringTest extends TestCase
{
    private const int AUDIT_KEY_LENGTH = 32;

    #[Test]
    public function deniedAuthorizationDecisionReachesTheAuditChain(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);

        $viewer = new Identity('user-77', 'Viewer', ['viewer']);

        self::assertFalse($gate->allows($viewer, 'manage-users'));

        $this->terminate($container);

        $entries = $sink->entries();
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame(AuditEvent::Authorization, $entry->event);
        self::assertSame(AuditOutcome::Denied, $entry->outcome);
        self::assertSame('authorization.denied', $entry->action);
        self::assertSame('user-77', $entry->actor);
        self::assertSame('manage-users', $entry->metadata['permission']);
        self::assertSame('default-deny', $entry->metadata['reason']);
    }

    #[Test]
    public function grantedAuthorizationDecisionReachesTheAuditChain(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);

        $editor = new Identity('user-12', 'Editor', ['editor']);

        self::assertTrue($gate->allows($editor, 'edit-posts'));

        $this->terminate($container);

        $entries = $sink->entries();
        self::assertCount(1, $entries);

        $entry = $entries[0];
        self::assertSame(AuditEvent::Authorization, $entry->event);
        self::assertSame(AuditOutcome::Success, $entry->outcome);
        self::assertSame('authorization.granted', $entry->action);
        self::assertSame('user-12', $entry->actor);
        self::assertSame('edit-posts', $entry->metadata['permission']);
        self::assertSame('RBAC', $entry->metadata['reason']);
    }

    /**
     * The decision is not on the write path, so it has to be taken off the
     * buffer by something. The kernel's terminate event runs after the response
     * has gone out — outside every decision, inside the process that made them.
     */
    #[Test]
    public function decisionsAreWrittenWhenTheKernelTerminates(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts');

        self::assertSame([], $sink->entries(), 'the write must not happen inside the decision');

        $this->terminate($container);

        self::assertCount(1, $sink->entries());
    }

    /**
     * The shipped configuration must not put a chained write inside a decision
     * at all. It used to: `record()` flushed the whole buffer the moment it
     * reached the threshold, so at the default of 64 every sixty-fourth
     * `allows()` paid for sixty-four HMAC-chained entries — the cost the sink
     * exists to keep off that path, delivered in one spike on an arbitrary
     * request.
     */
    #[Test]
    public function theShippedConfigurationWritesNothingInsideADecision(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $editor = new Identity('user-12', 'Editor', ['editor']);

        for ($i = 0; $i < 300; $i++) {
            $gate->allows($editor, 'edit-posts');
        }

        // Counted rather than compared: a failure here means hundreds of
        // entries, and the diff of hundreds of audit entries is a test run that
        // looks hung rather than a message that says what broke.
        self::assertSame(
            0,
            count($sink->entries()),
            'no decision may pay for a chained write on the shipped configuration',
        );

        $this->terminate($container);

        self::assertCount(300, $sink->entries());
    }

    /**
     * A buffer nothing empties would grow until the process ran out of memory.
     * The capacity is what stops it, and it is a ceiling rather than a batch
     * size: past it the sink writes the one oldest entry per further decision,
     * so what lands inside a decision is one write and not a whole buffer.
     */
    #[Test]
    public function theCapacityIsAMemoryCeilingAndNotABatch(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink, decisionAuditBuffer: 3);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $editor = new Identity('user-12', 'Editor', ['editor']);

        $gate->allows($editor, 'edit-posts');
        $gate->allows($editor, 'edit-posts');
        self::assertSame([], $sink->entries());

        $gate->allows($editor, 'edit-posts');
        self::assertCount(1, $sink->entries());

        $gate->allows($editor, 'edit-posts');
        self::assertCount(2, $sink->entries());

        $this->terminate($container);
        self::assertCount(4, $sink->entries());
    }

    /**
     * A queue worker never reaches `Kernel::terminate()`. It ends jobs, and the
     * end of a job is outside every decision, so the same listener is
     * registered there.
     */
    #[Test]
    public function aJobEndingDrainsTheBuffer(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts');

        self::assertSame([], $sink->entries());

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        $dispatcher->dispatch(new JobCompleted(
            jobId: 'job-1',
            queue: 'default',
            jobClass: 'App\\Job\\Anything',
            occurredAt: 1_700_000_000,
            attempt: 1,
            durationMs: 1.0,
        ));

        self::assertCount(1, $sink->entries());
    }

    /**
     * `pulsar optimize` compiles the listener map ahead of time, and
     * `EventMapCompiler` records a listener as a class and a method so the
     * compiled provider can resolve it from the container. A closure has
     * neither, and `AuthWiring` registered one on `TerminateEvent` whenever an
     * audit logger existed — so `optimize` threw
     * `closures cannot be compiled` on the framework's own wiring, on every
     * stock deployment, before it reached anything the application had written.
     */
    #[Test]
    public function theDrainPointListenersCompile(): void
    {
        $container = $this->bootContainer(new InMemoryAuditSink());

        /** @var ListenerProvider $provider */
        $provider = $container->get(ListenerProvider::class);

        $map = new EventMapCompiler()->compile($provider);

        foreach ([TerminateEvent::class, JobCompleted::class, JobFailed::class] as $drainPoint) {
            self::assertArrayHasKey($drainPoint, $map);
            self::assertSame(
                [['class' => AuthorizationDecisionFlushListener::class, 'method' => '__invoke', 'priority' => 0, 'moduleId' => 'auth']],
                $map[$drainPoint]['listeners'],
            );
        }
    }

    /**
     * Compiling is not the same as working. The compiled map resolves its
     * listener through the container, so the entry has to name a class the
     * container holds an instance of — one holding the configured sink, not a
     * second sink the container built to satisfy the lookup.
     */
    #[Test]
    public function theCompiledMapStillDrainsTheBuffer(): void
    {
        $auditSink = new InMemoryAuditSink();
        $container = $this->bootContainer($auditSink);

        /** @var ListenerProvider $provider */
        $provider = $container->get(ListenerProvider::class);
        $compiled = new CompiledListenerProvider(new EventMapCompiler()->compile($provider), $container);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts');

        self::assertSame([], $auditSink->entries());

        $dispatcher = new EventDispatcher($compiled, $compiled, new StormGuard(new StormProtectionConfig()));
        $dispatcher->dispatch(new TerminateEvent(
            $this->createStub(ServerRequestInterface::class),
            $this->createStub(ResponseInterface::class),
        ));

        self::assertCount(1, $auditSink->entries());
    }

    /**
     * `decision_audit_buffer: 1` is the deployment that will not accept a window
     * at all: the entry is chained before `allows()` returns.
     */
    #[Test]
    public function aThresholdOfOneWritesThroughInsideTheDecision(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink, decisionAuditBuffer: 1);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);

        $gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts');

        self::assertCount(1, $sink->entries());
    }

    /**
     * The mechanism this replaced dispatched the decision through
     * `EventDispatcherInterface`, so every listener the application had
     * registered ran inside every authorization check — and `EventDispatcher`
     * re-throws the first listener error after its loop, which turned an
     * unrelated listener's exception into a failed authorization.
     *
     * Nothing the application registers may be reached from a decision now. The
     * listener below throws on everything it is given; the decision must not
     * notice, and must still be recorded.
     */
    #[Test]
    public function anApplicationListenerIsNotReachedFromADecision(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        $seen = 0;
        /** @var ListenerProviderInterface $listeners */
        $listeners = $container->get(ListenerProviderInterface::class);
        $listeners->addListener(
            EventEnvelope::class,
            static function (object $event) use (&$seen): void {
                $seen++;

                throw new RuntimeException(
                    'an application listener that throws, given ' . $event::class,
                );
            },
            0,
            'app',
        );

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);

        self::assertTrue($gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts'));

        $this->terminate($container);

        self::assertSame(0, $seen, 'no application listener may run inside an authorization decision');
        self::assertCount(1, $sink->entries());
    }

    /**
     * Tamper evidence is the reason the record is worth writing. An assessor
     * who cannot tell an edited decision from an original one is reading a log,
     * not an audit trail.
     */
    #[Test]
    public function recordedDecisionsAreHmacChainedAndDetectTampering(): void
    {
        $auditKey = random_bytes(32);
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink, $auditKey);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);

        $editor = new Identity('user-12', 'Editor', ['editor']);
        $gate->allows($editor, 'edit-posts');
        $gate->allows($editor, 'delete-everything');

        $this->terminate($container);

        $entries = $sink->entries();
        self::assertCount(2, $entries);

        // Each entry verifies against the key, and each links to its
        // predecessor: excising the grant leaves the denial dangling.
        foreach ($entries as $entry) {
            self::assertTrue($entry->verify($auditKey));
        }

        self::assertSame($entries[0]->hmac, $entries[1]->previousHmac);

        $forged = new AuditEntry(
            id: $entries[1]->id,
            event: $entries[1]->event,
            outcome: AuditOutcome::Success,
            actor: $entries[1]->actor,
            action: 'authorization.granted',
            resource: $entries[1]->resource,
            timestamp: $entries[1]->timestamp,
            metadata: $entries[1]->metadata,
            previousHmac: $entries[1]->previousHmac,
            hmac: $entries[1]->hmac,
        );

        self::assertFalse($forged->verify($auditKey));
    }

    /**
     * A decision carries a CSPRNG nonce. Two entries with the same nonce are one
     * decision written twice — the property that makes a replayed record
     * detectable rather than merely suspicious.
     */
    #[Test]
    public function everyRecordedDecisionCarriesAFreshNonce(): void
    {
        $sink = new InMemoryAuditSink();
        $container = $this->bootContainer($sink);

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);

        $editor = new Identity('user-12', 'Editor', ['editor']);

        $gate->allows($editor, 'edit-posts');
        $gate->allows($editor, 'edit-posts');

        $this->terminate($container);

        $nonces = [];

        foreach ($sink->entries() as $entry) {
            /** @var mixed $nonce */
            $nonce = $entry->metadata['decision_nonce'] ?? null;
            self::assertIsString($nonce);
            self::assertNotSame('', $nonce);
            $nonces[$nonce] = true;
        }

        self::assertCount(2, $sink->entries());
        self::assertSame(2, count($nonces));
    }

    /**
     * `RequestContextMiddleware` clears the request context when the pipeline
     * unwinds, and the terminate flush runs after that. An entry built at flush
     * time from ambient state would carry no correlation id at all, so the
     * context is captured with the decision and passed explicitly.
     */
    #[Test]
    public function theEntryCarriesTheCorrelationIdOfTheRequestTheDecisionWasMadeIn(): void
    {
        $sink = new InMemoryAuditSink();
        $holder = new RequestContextHolder();
        $container = $this->bootContainer($sink, contextHolder: $holder);

        $correlationId = CorrelationId::generate();
        $holder->set(new RequestContext($correlationId, CausationId::generate()));

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts');

        // The request ends: the pipeline clears the context before the kernel
        // terminates.
        $holder->clear();
        $this->terminate($container);

        $entries = $sink->entries();
        self::assertCount(1, $entries);
        self::assertSame($correlationId->value, $entries[0]->metadata['correlation_id']);
    }

    /**
     * An application that binds its own sink keeps it — the framework's buffered
     * one is a default, not a fixture.
     */
    #[Test]
    public function anApplicationSuppliedSinkIsUsedInsteadOfTheDefault(): void
    {
        $auditSink = new InMemoryAuditSink();
        $applicationSink = new CountingDecisionSink();

        $container = $this->bootContainer(
            $auditSink,
            decisionSink: $applicationSink,
        );

        /** @var GateInterface $gate */
        $gate = $container->get(GateInterface::class);
        $gate->allows(new Identity('user-12', 'Editor', ['editor']), 'edit-posts');

        self::assertSame(1, $applicationSink->count);
        self::assertSame([], $auditSink->entries());
        self::assertSame($applicationSink, $container->get(AuthorizationDecisionSinkInterface::class));
    }

    private function terminate(Container $container): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);

        $dispatcher->dispatch(new TerminateEvent(
            $this->createStub(ServerRequestInterface::class),
            $this->createStub(ResponseInterface::class),
        ));
    }

    private function bootContainer(
        InMemoryAuditSink $sink,
        ?string $auditKey = null,
        ?RequestContextHolder $contextHolder = null,
        ?int $decisionAuditBuffer = null,
        ?AuthorizationDecisionSinkInterface $decisionSink = null,
    ): Container {
        $container = new Container();
        $router = new Router();
        $middleware = new MiddlewarePipeline($container);
        $middlewareRegistry = new MiddlewareRegistry();

        $auditLogger = new AuditLogger(
            $sink,
            $auditKey ?? random_bytes(self::AUDIT_KEY_LENGTH),
            null,
            $contextHolder,
        );
        $container->instance(AuditLogger::class, $auditLogger);
        $container->instance(AuditLoggerInterface::class, $auditLogger);

        if ($contextHolder !== null) {
            $container->instance(RequestContextHolder::class, $contextHolder);
        }

        if ($decisionSink !== null) {
            $container->instance(AuthorizationDecisionSinkInterface::class, $decisionSink);
        }

        $configManager = $this->createConfigManager($decisionAuditBuffer);
        $configManager->load();

        // EventWiring runs before AuthWiring in WiringList; running it here in
        // the same order is what makes the listener provider available for the
        // terminate flush.
        new EventWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);
        new AuthWiring()->wire($container, $configManager, $middleware, $middlewareRegistry, $router);

        return $container;
    }

    private function createConfigManager(?int $decisionAuditBuffer): ConfigManager
    {
        $configPath = sys_get_temp_dir() . '/pulsar_authz_audit_' . bin2hex(random_bytes(4));
        @mkdir($configPath, 0o755, true);

        $buffer = $decisionAuditBuffer === null
            ? ''
            : '"decision_audit_buffer" => ' . $decisionAuditBuffer . ',';

        file_put_contents($configPath . '/app.php', '<?php return ["name" => "Test", "env" => "testing", "debug" => false, "timezone" => "UTC", "locale" => "en"];');
        file_put_contents($configPath . '/observability.php', '<?php return ["logging" => ["default_channel" => "file", "level" => "debug", "channels" => []]];');
        file_put_contents($configPath . '/security.php', '<?php return ["session" => [], "csrf" => [], "headers" => [], "rate_limit" => [], "auth" => ["default_guard" => "session", "two_factor" => ["enabled" => false], "authorization" => ["roles" => ["editor" => ["permissions" => ["edit-posts"]]], "super_roles" => [], ' . $buffer . ']]];');

        return new ConfigManager($configPath);
    }
}

/**
 * An application-supplied decision sink: it counts, and it writes nowhere.
 */
final class CountingDecisionSink implements AuthorizationDecisionSinkInterface
{
    public int $count = 0;

    public function record(AuthorizationDecision $decision): void
    {
        unset($decision);

        $this->count++;
    }
}
