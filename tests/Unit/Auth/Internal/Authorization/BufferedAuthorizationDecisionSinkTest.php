<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Internal\Authorization;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\AuthorizationDecision;
use Pulsar\Auth\Authorization\Event\AuthorizationDenied;
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;
use Pulsar\Auth\Internal\Authorization\BufferedAuthorizationDecisionSink;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Audit\AuditSinkInterface;
use RuntimeException;
use Stringable;

use function microtime;
use function random_bytes;

#[CoversClass(BufferedAuthorizationDecisionSink::class)]
final class BufferedAuthorizationDecisionSinkTest extends TestCase
{
    #[Test]
    public function nothingIsWrittenUntilTheBufferIsFlushed(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->record($this->grant('posts.create'));
        $sink->record($this->refusal('posts.delete'));

        self::assertSame(2, $sink->buffered());
        self::assertSame([], $entries->entries);

        $sink->flush();

        self::assertSame(0, $sink->buffered());
        self::assertCount(2, $entries->entries);
    }

    #[Test]
    public function aGrantAndARefusalAreWrittenWithTheirOwnOutcomeAndAction(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->record($this->grant('posts.create'));
        $sink->record($this->refusal('posts.delete'));
        $sink->flush();

        self::assertSame(AuditEvent::Authorization, $entries->entries[0]->event);
        self::assertSame(AuditOutcome::Success, $entries->entries[0]->outcome);
        self::assertSame('authorization.granted', $entries->entries[0]->action);
        self::assertSame(AuthorizationGranted::class, $entries->entries[0]->metadata['event_type']);

        self::assertSame(AuditOutcome::Denied, $entries->entries[1]->outcome);
        self::assertSame('authorization.denied', $entries->entries[1]->action);
        self::assertSame(AuthorizationDenied::class, $entries->entries[1]->metadata['event_type']);
    }

    /**
     * The instant is the decision's, not the flush's. An entry that stamped
     * itself at write time would say a decision happened when the buffer
     * happened to drain.
     */
    #[Test]
    public function theEntryRecordsWhenTheDecisionWasReachedNotWhenItWasWritten(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $decision = new AuthorizationDecision(
            identityId: 'user-1',
            permission: 'posts.create',
            resource: null,
            allowed: true,
            reason: 'RBAC',
            decidedAtUnix: 1_700_000_000.123456,
        );

        $sink->record($decision);
        $sink->flush();

        self::assertSame(
            '2023-11-14T22:13:20.123456+00:00',
            $entries->entries[0]->metadata['decided_at'],
        );
    }

    #[Test]
    public function anUnauthenticatedDecisionIsRecordedAgainstAnAnonymousActor(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->record(new AuthorizationDecision(
            identityId: '',
            permission: 'admin.panel',
            resource: null,
            allowed: false,
            reason: 'default-deny',
            decidedAtUnix: microtime(true),
        ));
        $sink->flush();

        self::assertCount(1, $entries->entries);
        self::assertSame(AuditActor::anonymous()->id, $entries->entries[0]->actor);
    }

    #[Test]
    public function theResourceReachesTheEntry(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->record(new AuthorizationDecision(
            identityId: 'user-1',
            permission: 'posts.update',
            resource: 'post-42',
            allowed: true,
            reason: 'ABAC',
            decidedAtUnix: microtime(true),
        ));
        $sink->flush();

        self::assertSame('post-42', $entries->entries[0]->resource);
    }

    /**
     * The payload hash is what makes a rewritten record detectable: an entry
     * whose fields were edited no longer hashes to the value recorded beside it.
     */
    #[Test]
    public function theEntryCitesAPayloadHashThatCoversTheDecision(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->record($this->grant('posts.create'));
        $sink->record($this->grant('posts.publish'));
        $sink->flush();

        /** @var mixed $first */
        $first = $entries->entries[0]->metadata['payload_hash'];
        /** @var mixed $second */
        $second = $entries->entries[1]->metadata['payload_hash'];

        self::assertIsString($first);
        self::assertNotSame('', $first);
        self::assertNotSame($first, $second);
    }

    #[Test]
    public function everyEntryCarriesItsOwnNonce(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->record($this->grant('posts.create'));
        $sink->record($this->grant('posts.create'));
        $sink->flush();

        self::assertNotSame(
            $entries->entries[0]->metadata['decision_nonce'],
            $entries->entries[1]->metadata['decision_nonce'],
        );
    }

    #[Test]
    public function theCapturedRequestContextIsWrittenEvenAfterItHasBeenCleared(): void
    {
        $entries = new CapturingAuditSink();
        $holder = new RequestContextHolder();
        $correlationId = CorrelationId::generate();
        $causationId = CausationId::generate();
        $holder->set(new RequestContext($correlationId, $causationId));

        $sink = $this->sink($entries, $holder);
        $sink->record($this->grant('posts.create'));

        $holder->clear();
        $sink->flush();

        self::assertSame($correlationId->value, $entries->entries[0]->metadata['correlation_id']);
        self::assertSame($causationId->value, $entries->entries[0]->metadata['causation_id']);
    }

    /**
     * One record the audit chain rejects must not cost the trail the records
     * behind it.
     */
    #[Test]
    public function aFailedWriteIsReportedAndTheRestOfTheBufferStillLands(): void
    {
        $entries = new CapturingAuditSink();
        $logger = new CapturingLogger();
        $auditLogger = new RefusingAuditLogger(new AuditLogger($entries, random_bytes(32)));

        $sink = new BufferedAuthorizationDecisionSink(
            auditLogger: $auditLogger,
            logger: $logger,
        );

        $auditLogger->refuse = 'posts.create';

        $sink->record($this->grant('posts.create'));
        $sink->record($this->grant('posts.publish'));
        $sink->flush();

        self::assertCount(1, $entries->entries);
        self::assertSame('posts.publish', $entries->entries[0]->metadata['permission']);
        self::assertContains('Authorization decision could not be audited', $logger->critical);
    }

    #[Test]
    public function aCapacityBelowOneIsClampedToWriteThrough(): void
    {
        $entries = new CapturingAuditSink();

        $sink = new BufferedAuthorizationDecisionSink(
            auditLogger: new AuditLogger($entries, random_bytes(32)),
            capacity: 0,
        );

        $sink->record($this->grant('posts.create'));

        self::assertCount(1, $entries->entries);
        self::assertSame(0, $sink->buffered());
    }

    /**
     * An audit sink that reaches the Gate on its way to disk makes writing one
     * entry produce another decision. The drain must stop rather than run for
     * ever, and what it could not take must stay buffered rather than be lost.
     */
    #[Test]
    public function aWriteThatKeepsProducingDecisionsStopsAtThePassCeiling(): void
    {
        $entries = new CapturingAuditSink();
        $logger = new CapturingLogger();

        $sink = new BufferedAuthorizationDecisionSink(
            auditLogger: new AuditLogger($entries, random_bytes(32)),
            logger: $logger,
            capacity: 1_000_000,
        );

        $entries->onWrite = function () use ($sink): void {
            $sink->record($this->grant('sink.probe'));
        };

        $sink->record($this->grant('posts.create'));
        $sink->flush();

        // One entry per pass, and the pass that could not run left its decision
        // in the buffer for the next flush.
        self::assertCount(8, $entries->entries);
        self::assertSame(1, $sink->buffered());
        self::assertContains(
            'Authorization decision audit flush hit its pass ceiling; writing an entry keeps producing decisions',
            $logger->critical,
        );

        // The next flush makes progress on what was left rather than refusing
        // it: the ceiling costs latency, not records.
        $entries->onWrite = null;
        $sink->flush();

        self::assertCount(9, $entries->entries);
        self::assertSame(0, $sink->buffered());
    }

    /**
     * The capacity is a memory ceiling, not a batch size. Reaching it used to
     * drain the whole buffer from inside `record()` — sixty-four chained writes
     * landing in one `Gate::allows()` at the shipped default. It now writes the
     * one entry it must to stay inside the ceiling, oldest first, and says once
     * that a drain point should have run.
     */
    #[Test]
    public function atCapacityOneEntryIsWrittenPerDecisionOldestFirst(): void
    {
        $entries = new CapturingAuditSink();
        $logger = new CapturingLogger();

        $sink = new BufferedAuthorizationDecisionSink(
            auditLogger: new AuditLogger($entries, random_bytes(32)),
            logger: $logger,
            capacity: 3,
        );

        foreach (['one', 'two', 'three', 'four', 'five'] as $permission) {
            $sink->record($this->grant($permission));
        }

        self::assertCount(3, $entries->entries);
        self::assertSame('one', $entries->entries[0]->metadata['permission']);
        self::assertSame('two', $entries->entries[1]->metadata['permission']);
        self::assertSame('three', $entries->entries[2]->metadata['permission']);
        self::assertSame(2, $sink->buffered());

        self::assertSame(
            ['Authorization decision buffer reached its capacity; a drain point should have emptied it'],
            $logger->critical,
            'the capacity is a deployment defect, worth saying once and not once per decision',
        );
    }

    /**
     * `decision_audit_buffer: 1` is the operator asking for the chained write
     * inside every decision. That is a configuration, not a defect, so it is
     * not reported.
     */
    #[Test]
    public function writeThroughIsSilent(): void
    {
        $entries = new CapturingAuditSink();
        $logger = new CapturingLogger();

        $sink = new BufferedAuthorizationDecisionSink(
            auditLogger: new AuditLogger($entries, random_bytes(32)),
            logger: $logger,
            capacity: 1,
        );

        $sink->record($this->grant('posts.create'));
        $sink->record($this->grant('posts.publish'));

        self::assertCount(2, $entries->entries);
        self::assertSame(0, $sink->buffered());
        self::assertSame([], $logger->critical);
    }

    /**
     * Every report this class makes is about a failure it has already
     * contained. A logger that throws would turn each of them back into an
     * uncontained one — inside `Gate::allows()` for the capacity write, and
     * inside `__destruct()` on the way out of a process that was exiting
     * cleanly, which ADR-0052 states this class cannot do.
     */
    #[Test]
    public function aThrowingLoggerCannotEscapeAnyReportingPath(): void
    {
        $entries = new CapturingAuditSink();
        $auditLogger = new RefusingAuditLogger(new AuditLogger($entries, random_bytes(32)));

        $sink = new BufferedAuthorizationDecisionSink(
            auditLogger: $auditLogger,
            logger: new ThrowingLogger(),
            capacity: 2,
        );

        // The failed write reports, and the report throws.
        $auditLogger->refuse = 'posts.create';
        $sink->record($this->grant('posts.create'));
        $sink->record($this->grant('posts.publish'));
        $sink->record($this->grant('posts.delete'));

        // Two decisions are still in hand; destroying the sink writes them and
        // must not raise on the way out.
        $auditLogger->refuse = 'posts.delete';
        $sink->__destruct();

        self::assertSame(0, $sink->buffered());
        self::assertCount(1, $entries->entries);
        self::assertSame('posts.publish', $entries->entries[0]->metadata['permission']);
    }

    #[Test]
    public function flushingAnEmptyBufferIsHarmless(): void
    {
        $entries = new CapturingAuditSink();
        $sink = $this->sink($entries);

        $sink->flush();
        $sink->flush();

        self::assertSame([], $entries->entries);
    }

    private function sink(
        CapturingAuditSink $entries,
        ?RequestContextHolder $holder = null,
    ): BufferedAuthorizationDecisionSink {
        return new BufferedAuthorizationDecisionSink(
            auditLogger: new AuditLogger($entries, random_bytes(32), null, $holder),
            contextHolder: $holder,
            capacity: 1024,
        );
    }

    private function grant(string $permission): AuthorizationDecision
    {
        return new AuthorizationDecision(
            identityId: 'user-1',
            permission: $permission,
            resource: null,
            allowed: true,
            reason: 'RBAC',
            decidedAtUnix: microtime(true),
        );
    }

    private function refusal(string $permission): AuthorizationDecision
    {
        return new AuthorizationDecision(
            identityId: 'user-1',
            permission: $permission,
            resource: null,
            allowed: false,
            reason: 'default-deny',
            decidedAtUnix: microtime(true),
        );
    }
}

final class CapturingAuditSink implements AuditSinkInterface
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    /** Runs after each write, so a test can make writing produce more work. */
    public ?Closure $onWrite = null;

    public function write(AuditEntry $entry): void
    {
        $this->entries[] = $entry;

        if ($this->onWrite !== null) {
            ($this->onWrite)();
        }
    }
}

/**
 * Refuses exactly one permission, so a partially-failing flush can be asserted.
 */
final class RefusingAuditLogger implements AuditLoggerInterface
{
    public ?string $refuse = null;

    public function __construct(private readonly AuditLoggerInterface $inner) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        if ($this->refuse !== null && ($metadata['permission'] ?? null) === $this->refuse) {
            throw new RuntimeException('audit sink refused the write');
        }

        return $this->inner->log($event, $outcome, $actor, $action, $resource, $metadata);
    }
}

/**
 * The application logger having a worse day than the audit sink.
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

final class CapturingLogger extends AbstractLogger
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
