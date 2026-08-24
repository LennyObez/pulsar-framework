<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pulsar\Auth\Authorization\AuthorizationDecision;
use Pulsar\Auth\Authorization\AuthorizationDecisionSinkInterface;
use Pulsar\Auth\Authorization\DecisionRecordingState;
use Pulsar\Auth\Authorization\Gate;
use Pulsar\Auth\Authorization\InMemoryRoleRegistry;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\PolicyContext;
use Pulsar\Auth\Authorization\PolicyInterface;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Identity\Identity;
use RuntimeException;
use Stringable;

use function array_column;
use function array_map;
use function count;
use function microtime;
use function spl_object_id;

/**
 * The Gate hands every decision it reaches to a dedicated sink.
 *
 * The mechanism this replaced dispatched through the application's event
 * dispatcher, which put every listener the application had registered inside
 * every authorization decision: `EventDispatcher` re-throws the first listener
 * error after its loop, so an unrelated listener throwing turned a grant into a
 * `500`, and a listener asking the Gate a question re-entered the decision it
 * was being told about until `StormGuard` threw. Both are asserted against
 * here, from the attacker's side: the sink misbehaves and the decision must
 * not.
 */
#[CoversClass(Gate::class)]
#[CoversClass(AuthorizationDecision::class)]
#[CoversClass(DecisionRecordingState::class)]
final class GateDecisionRecordingTest extends TestCase
{
    private InMemoryRoleRegistry $registry;

    private RecordingSink $sink;

    protected function setUp(): void
    {
        $this->registry = new InMemoryRoleRegistry();
        $this->registry->register(new Role('editor', [new Permission('posts.create')]));
        $this->sink = new RecordingSink();
    }

    #[Test]
    public function gateWorksWithoutASink(): void
    {
        $gate = new Gate($this->registry);

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));
    }

    #[Test]
    public function rbacGrantIsRecorded(): void
    {
        $gate = new Gate($this->registry, decisionSink: $this->sink);

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));

        self::assertCount(1, $this->sink->decisions);
        $decision = $this->sink->decisions[0];
        self::assertTrue($decision->allowed);
        self::assertSame('RBAC', $decision->reason);
        self::assertSame('user-1', $decision->identityId);
        self::assertSame('posts.create', $decision->permission);
        self::assertNull($decision->resource);
    }

    #[Test]
    public function superRoleGrantIsRecorded(): void
    {
        $gate = new Gate($this->registry, superRoles: ['superadmin'], decisionSink: $this->sink);
        $identity = new Identity(id: 'admin-1', displayName: 'Super', roles: ['superadmin']);

        self::assertTrue($gate->allows($identity, 'anything'));

        self::assertCount(1, $this->sink->decisions);
        self::assertTrue($this->sink->decisions[0]->allowed);
        self::assertSame('super-role', $this->sink->decisions[0]->reason);
    }

    #[Test]
    public function abacDenyIsRecorded(): void
    {
        $gate = new Gate($this->registry, decisionSink: $this->sink);
        $gate->addPolicy($this->policyReturning(false));

        self::assertFalse($gate->allows($this->editor(), 'posts.create'));

        self::assertCount(1, $this->sink->decisions);
        self::assertFalse($this->sink->decisions[0]->allowed);
        self::assertSame('ABAC-deny', $this->sink->decisions[0]->reason);
    }

    #[Test]
    public function abacAllowIsRecorded(): void
    {
        $gate = new Gate($this->registry, decisionSink: $this->sink);
        $gate->addPolicy($this->policyReturning(true));

        $identity = new Identity(id: 'user-4', displayName: 'Special', roles: []);

        self::assertTrue($gate->allows($identity, 'special.access'));

        self::assertCount(1, $this->sink->decisions);
        self::assertTrue($this->sink->decisions[0]->allowed);
        self::assertSame('ABAC', $this->sink->decisions[0]->reason);
    }

    #[Test]
    public function defaultDenyIsRecorded(): void
    {
        $gate = new Gate($this->registry, decisionSink: $this->sink);
        $identity = new Identity(id: 'user-3', displayName: 'NoPerms', roles: []);

        self::assertFalse($gate->allows($identity, 'admin.panel'));

        self::assertCount(1, $this->sink->decisions);
        self::assertFalse($this->sink->decisions[0]->allowed);
        self::assertSame('default-deny', $this->sink->decisions[0]->reason);
    }

    #[Test]
    public function recordedDecisionCarriesTheResourceFromThePolicyContext(): void
    {
        $gate = new Gate($this->registry, decisionSink: $this->sink);

        $gate->allows($this->editor(), 'posts.create', new PolicyContext('posts.create', 'post-42'));

        self::assertSame('post-42', $this->sink->decisions[0]->resource);
    }

    /**
     * The resource has to read the same whether or not a policy is registered:
     * the Gate skips building a default `PolicyContext` when it has no policies
     * to hand one to, and that must not be observable in the record.
     */
    #[Test]
    public function resourceIsRecordedIdenticallyWithAndWithoutPolicies(): void
    {
        $withoutPolicies = new Gate($this->registry, decisionSink: $this->sink);
        $withoutPolicies->allows($this->editor(), 'posts.create', new PolicyContext('posts.create', 'post-7'));

        $withPolicies = new Gate($this->registry, decisionSink: $this->sink);
        $withPolicies->addPolicy($this->policyReturning(null));
        $withPolicies->allows($this->editor(), 'posts.create', new PolicyContext('posts.create', 'post-7'));

        self::assertCount(2, $this->sink->decisions);
        self::assertSame('post-7', $this->sink->decisions[0]->resource);
        self::assertSame('post-7', $this->sink->decisions[1]->resource);
        self::assertSame('RBAC', $this->sink->decisions[1]->reason);
    }

    #[Test]
    public function decisionCarriesTheInstantItWasReached(): void
    {
        $before = microtime(true);
        $gate = new Gate($this->registry, decisionSink: $this->sink);
        $gate->allows($this->editor(), 'posts.create');
        $after = microtime(true);

        $decidedAt = $this->sink->decisions[0]->decidedAtUnix;
        self::assertGreaterThanOrEqual($before, $decidedAt);
        self::assertLessThanOrEqual($after, $decidedAt);

        self::assertSame(
            (int) $decidedAt,
            (int) $this->sink->decisions[0]->decidedAt()->format('U'),
        );
    }

    /**
     * A sink that throws must not be able to turn an allow into a `500`. This
     * is the defect the dispatcher wiring shipped: `EventDispatcher` collects
     * listener errors and re-throws the first one after its loop, so an
     * unrelated listener's exception surfaced out of `Gate::allows()`.
     */
    #[Test]
    public function aThrowingSinkDoesNotChangeAGrant(): void
    {
        $logger = new CollectingLogger();
        $gate = new Gate($this->registry, decisionSink: new ThrowingSink(), logger: $logger);

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));
        self::assertContains('Authorization decision could not be recorded', $logger->critical);
    }

    #[Test]
    public function aThrowingSinkDoesNotChangeADenial(): void
    {
        $gate = new Gate($this->registry, decisionSink: new ThrowingSink());
        $identity = new Identity(id: 'user-3', displayName: 'NoPerms', roles: []);

        self::assertFalse($gate->allows($identity, 'admin.panel'));
    }

    /**
     * A sink that asks the Gate a question is re-entering the decision it was
     * handed. The nested decision must be refused a second frame of recording —
     * and still be written, after the outer one, in order.
     */
    #[Test]
    public function aReentrantSinkIsRefusedRatherThanRecursing(): void
    {
        $sink = new ReentrantSink();
        $gate = new Gate($this->registry, decisionSink: $sink);
        $sink->gate = $gate;

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));

        // The outer decision, then the one the sink caused — both recorded,
        // never nested: the sink saw its own re-entry return without a further
        // record() frame being opened.
        self::assertSame(['posts.create', 'sink.probe'], $sink->permissions());
        self::assertSame(1, $sink->maxDepthSeen);
    }

    /**
     * A sink that asks a question for every record it takes cannot be allowed
     * to run for ever. The Gate stops at its ceiling, says so at `critical`,
     * and the decision it was called about is still returned and still written.
     */
    #[Test]
    public function anUnboundedlyReentrantSinkTerminatesAndIsReportedLoudly(): void
    {
        $logger = new CollectingLogger();
        $sink = new ReentrantSink(alwaysProbe: true);
        $gate = new Gate($this->registry, decisionSink: $sink, logger: $logger);
        $sink->gate = $gate;

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));

        self::assertSame('posts.create', $sink->permissions()[0]);
        self::assertLessThanOrEqual(17, count($sink->permissions()));
        self::assertSame(1, $sink->maxDepthSeen);
        self::assertContains(
            'Authorization decision sink re-entered the Gate; nested decisions were refused',
            $logger->critical,
        );
    }

    /**
     * The refusal counters are per outer decision. A Gate that recorded a
     * re-entrant burst must record the next, ordinary decision the same way it
     * would have before.
     */
    #[Test]
    public function theGateRecordsNormallyAfterARefusedBurst(): void
    {
        $sink = new ReentrantSink(alwaysProbe: true);
        $gate = new Gate($this->registry, decisionSink: $sink, logger: new CollectingLogger());
        $sink->gate = $gate;

        $gate->allows($this->editor(), 'posts.create');
        $sink->stop();
        $sink->reset();

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));
        self::assertSame(['posts.create'], $sink->permissions());
    }

    /**
     * Two Fibers deciding at once, with a sink that suspends inside the record
     * it was handed — which the sink `AuthWiring` binds does, because
     * `AuditLogger` spins cooperatively on its chain lock.
     *
     * The re-entry guard used to be a property of the Gate, so it answered "is
     * this Gate recording anywhere" rather than "is this call stack inside a
     * record". Fiber B's decision therefore read as B re-entering A's record:
     * it was queued against A, handed to the sink from A's drain, and counted
     * against A's ceiling. Each decision must be handed to the sink on the call
     * stack that reached it.
     */
    #[Test]
    public function twoFibersDecidingAtOnceAreRecordedOnTheirOwnCallStacks(): void
    {
        $sink = new SuspendingSink();
        $gate = new Gate($this->registry, decisionSink: $sink);

        /** @var array<string, int> $stackOf */
        $stackOf = [];

        $decide = static function (string $id) use ($gate, &$stackOf): Fiber {
            return new Fiber(static function () use ($gate, $id, &$stackOf): void {
                $current = Fiber::getCurrent();
                $stackOf[$id] = $current === null ? 0 : spl_object_id($current);

                $gate->allows(new Identity(id: $id, displayName: $id, roles: ['editor']), 'posts.create');
            });
        };

        $a = $decide('user-a');
        $b = $decide('user-b');

        $a->start();
        $b->start();
        $this->driveToCompletion([$a, $b]);

        self::assertCount(2, $sink->records);

        foreach ($sink->records as [$identityId, $stack]) {
            self::assertSame(
                $stackOf[$identityId],
                $stack,
                'a decision must be recorded on the call stack that reached it',
            );
        }

        self::assertSame(
            ['user-a', 'user-b'],
            array_column($sink->records, 0),
            'both decisions are recorded, each exactly once',
        );
    }

    /**
     * The nesting ceiling bounds what one sink may cause from inside one
     * record. It must not bound how many Fibers may decide at once: with the
     * guard on the instance, the seventeenth concurrent decision was refused
     * and the rest were dropped outright by the first Fiber's reset. Twenty
     * Fibers deciding while the first one's sink is suspended are twenty
     * decisions, not seventeen.
     */
    #[Test]
    public function noDecisionIsLostWhenMoreFibersDecideThanTheNestingCeiling(): void
    {
        $sink = new SuspendingSink();
        $logger = new CollectingLogger();
        $gate = new Gate($this->registry, decisionSink: $sink, logger: $logger);

        $fibers = [];
        $expected = [];

        for ($i = 0; $i < 20; $i++) {
            $id = 'user-' . $i;
            $expected[] = $id;

            $fibers[] = new Fiber(static function () use ($gate, $id): void {
                $gate->allows(new Identity(id: $id, displayName: $id, roles: ['editor']), 'posts.create');
            });
        }

        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        $this->driveToCompletion($fibers);

        self::assertSame($expected, array_column($sink->records, 0));
        self::assertSame([], $logger->critical, 'concurrency is not re-entry and must not be reported as it');
    }

    /**
     * The Gate catches everything the sink raises so an audit fault cannot
     * change an outcome — and then reported it through an application-supplied
     * logger from inside the `catch`. A logger that throws put the fault
     * straight back on the caller, one frame further out: the exact defect
     * ADR-0052 exists to remove.
     */
    #[Test]
    public function aThrowingLoggerDoesNotChangeAGrant(): void
    {
        $gate = new Gate(
            $this->registry,
            decisionSink: new ThrowingSink(),
            logger: new ThrowingLogger(),
        );

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));
    }

    #[Test]
    public function aThrowingLoggerDoesNotChangeADenial(): void
    {
        $gate = new Gate(
            $this->registry,
            decisionSink: new ThrowingSink(),
            logger: new ThrowingLogger(),
        );

        $identity = new Identity(id: 'user-3', displayName: 'NoPerms', roles: []);

        self::assertFalse($gate->allows($identity, 'admin.panel'));
    }

    /**
     * The other report is made from a `finally`, where a throw does not merely
     * surface — it replaces whatever was being unwound.
     */
    #[Test]
    public function aThrowingLoggerDoesNotChangeADecisionThatRefusedNesting(): void
    {
        $sink = new ReentrantSink(alwaysProbe: true);
        $gate = new Gate($this->registry, decisionSink: $sink, logger: new ThrowingLogger());
        $sink->gate = $gate;

        self::assertTrue($gate->allows($this->editor(), 'posts.create'));
    }

    /**
     * Runs every fiber that is still suspended until none is, so a test does
     * not have to predict how many times each one suspends.
     *
     * @param list<Fiber<mixed, mixed, mixed, mixed>> $fibers
     */
    private function driveToCompletion(array $fibers): void
    {
        do {
            $progressed = false;

            foreach ($fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    $progressed = true;
                }
            }
        } while ($progressed);
    }

    private function editor(): Identity
    {
        return new Identity(id: 'user-1', displayName: 'Test', roles: ['editor']);
    }

    private function policyReturning(?bool $result): PolicyInterface
    {
        $policy = $this->createStub(PolicyInterface::class);
        $policy->method('evaluate')->willReturn($result);

        return $policy;
    }
}

/**
 * Collects what the Gate hands it and nothing else.
 */
final class RecordingSink implements AuthorizationDecisionSinkInterface
{
    /** @var list<AuthorizationDecision> */
    public array $decisions = [];

    public function record(AuthorizationDecision $decision): void
    {
        $this->decisions[] = $decision;
    }
}

/**
 * The listener that used to be able to turn an allow into a 500.
 */
final class ThrowingSink implements AuthorizationDecisionSinkInterface
{
    public function record(AuthorizationDecision $decision): void
    {
        throw new RuntimeException('audit sink unavailable: ' . $decision->permission);
    }
}

/**
 * Asks the Gate a question from inside the record it was handed.
 */
final class ReentrantSink implements AuthorizationDecisionSinkInterface
{
    public ?Gate $gate = null;

    public int $maxDepthSeen = 0;

    private int $depth = 0;

    private bool $stopped = false;

    /** @var list<AuthorizationDecision> */
    private array $decisions = [];

    public function __construct(private readonly bool $alwaysProbe = false) {}

    public function record(AuthorizationDecision $decision): void
    {
        $this->depth++;
        $this->maxDepthSeen = $this->maxDepthSeen < $this->depth ? $this->depth : $this->maxDepthSeen;
        $this->decisions[] = $decision;

        $probe = !$this->stopped
            && $this->gate !== null
            && ($this->alwaysProbe || $decision->permission !== 'sink.probe');

        if ($probe) {
            $this->gate->allows(
                new Identity(id: 'sink', displayName: 'Sink', roles: ['editor']),
                'sink.probe',
            );
        }

        $this->depth--;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_map(
            static fn(AuthorizationDecision $decision): string => $decision->permission,
            $this->decisions,
        );
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function reset(): void
    {
        $this->decisions = [];
        $this->maxDepthSeen = 0;
    }
}

/**
 * Suspends inside the record it was handed, the way a sink reaching
 * `AuditLogger` does when it meets a held chain lock, and remembers which call
 * stack each record ran on.
 */
final class SuspendingSink implements AuthorizationDecisionSinkInterface
{
    /**
     * Identity id, and the object id of the Fiber the record ran on (`0` for
     * code running outside every Fiber).
     *
     * @var list<array{string, int}>
     */
    public array $records = [];

    public function record(AuthorizationDecision $decision): void
    {
        $current = Fiber::getCurrent();

        $this->records[] = [$decision->identityId, $current === null ? 0 : spl_object_id($current)];

        if ($current !== null) {
            Fiber::suspend();
        }
    }
}

/**
 * The application's logger, having a worse day than the sink.
 */
final class ThrowingLogger extends AbstractLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        unset($level, $context);

        throw new RuntimeException('log target unavailable: ' . $message);
    }
}

/**
 * Keeps the `critical` messages so a test can assert the Gate was loud.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $critical = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($level === 'critical') {
            $this->critical[] = (string) $message;
        }
    }
}
