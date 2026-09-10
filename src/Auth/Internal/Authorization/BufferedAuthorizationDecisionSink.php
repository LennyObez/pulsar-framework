<?php

declare(strict_types=1);

namespace Pulsar\Auth\Internal\Authorization;

use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Auth\Authorization\AuthorizationDecision;
use Pulsar\Auth\Authorization\Event\AuthorizationDenied;
use Pulsar\Auth\Authorization\Event\AuthorizationGranted;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Context\RequestContext;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Random\Engine\Secure;
use Random\Randomizer;
use Throwable;

use function array_shift;
use function bin2hex;
use function count;
use function max;

/**
 * Writes the Gate's decisions into the tamper-evident audit chain, without
 * doing it on the authorization path.
 *
 * ## Why this buffers
 *
 * Writing one HMAC-chained audit entry costs about 20 µs on the machine this
 * was measured on, against a 4 µs authorization decision. Writing it inside
 * `Gate::allows()` would make every authorization check six times the cost of
 * the check itself, on a path a request crosses once per protected resource.
 * `record()` therefore does the one thing that must happen while the decision
 * is fresh — capture it, and the request context it was made in — and returns.
 * The entries are built and chained by {@see flush()}, after the decision has
 * gone back to its caller.
 *
 * ## Where the write happens instead
 *
 * At drain points, and a drain point is by definition somewhere no decision is
 * in flight. `AuthWiring` registers {@see AuthorizationDecisionFlushListener}
 * on three of them: the kernel's `TerminateEvent`, which runs after the
 * response has been written, and the queue's `JobCompleted` / `JobFailed`,
 * which run between jobs. {@see __destruct()} is the fourth and covers what
 * none of those do — a console command, and a request served by a runtime that
 * never calls `Kernel::terminate()`.
 *
 * `record()` itself writes nothing. That is the whole point, and it is a
 * property rather than a tendency: the shipped configuration cannot reach a
 * write from inside `Gate::allows()`. What used to break it was this class
 * calling `flush()` the moment the buffer reached its threshold — at the
 * shipped default, sixty-four HMAC-chained writes landing inside one arbitrary
 * authorization check, which is the cost this class exists to keep off that
 * path.
 *
 * ## The capacity, and the two ways past it
 *
 * `$capacity` is how many decisions may be held, not how many are batched. Two
 * things happen at it, and only one of them is on the decision path:
 *
 * - **`capacity: 1`** is write-through: the entry is chained before `allows()`
 *   returns. That is a configured, visible choice
 *   (`auth.authorization.decision_audit_buffer`) made by a deployment that will
 *   not accept a durability window, and the operator paying the latency is the
 *   one who decides.
 * - **`capacity > 1`**, once reached, means a drain point should have run and
 *   did not — a unit of work making more authorization decisions than the
 *   shipped ceiling, or a process with no drain point at all. Rather than grow
 *   until the process runs out of memory, the sink writes exactly one entry,
 *   the oldest, per further decision. Memory stays pinned at the capacity, the
 *   trail stays in order, and the cost inside a decision is one write rather
 *   than a whole buffer. It is reported at `critical` the first time, because
 *   it is a deployment defect and not a workload.
 *
 * ## What that trades away, and how it is bounded
 *
 * A decision captured and not yet written is a decision a hard process death
 * would lose. What bounds it is the distance to the next drain point: for a
 * served request that is the request, for a queue worker that is the job, and
 * the capacity is the ceiling behind both. A deployment that will not accept
 * any window sets the capacity to `1`.
 *
 * What none of that survives is the process being killed outright — a segfault,
 * an OOM, a `kill -9`. An uncaught exception is not in that class: the kernel
 * renders it into a response and the process exits normally, running both the
 * terminate flush and the destructor.
 *
 * ## Why the context is captured rather than read at write time
 *
 * `AuditLogger` enriches entries with the correlation and causation ids of the
 * ambient `RequestContext`. `RequestContextMiddleware` clears that context when
 * the pipeline unwinds, and the terminate flush runs after it — so an entry
 * built at flush time would find no context and carry no correlation id at all.
 * `record()` captures the context with the decision and the ids are passed
 * explicitly, which also makes them right rather than merely present: they name
 * the request the decision was made in, not the one that happened to be open
 * when it was written.
 *
 * ## What makes an entry evidence
 *
 * - *Tamper evidence* comes from {@see AuditLoggerInterface}: entries are
 *   HMAC-chained to their predecessors, so an altered or excised decision
 *   breaks verification downstream.
 * - *Forgery resistance for the payload* comes from the envelope's
 *   `payloadHash`, recorded alongside the decision: a record whose fields were
 *   rewritten no longer hashes to the value computed here.
 * - *Replay detection* comes from the `decision_nonce`, minted with a CSPRNG.
 *   Two entries carrying one nonce are one decision written twice.
 *
 * The entry's own `timestamp` is the moment it was written; `decided_at` in its
 * metadata is the moment the decision was reached. A reader comparing the two
 * is reading flush latency.
 */
#[Internal]
final class BufferedAuthorizationDecisionSink implements BufferedDecisionSinkInterface
{
    /**
     * Decisions the sink may hold.
     *
     * Sized above what a unit of work decides rather than at what one batch
     * should be: a request that authorizes a thousand distinct actions is
     * already pathological, and a buffered decision is a handful of scalars, so
     * a full buffer is a few hundred kilobytes. That headroom is what makes
     * "the chained write is not inside a decision" true of the shipped
     * configuration instead of true for the first sixty-four of them.
     */
    public const int DEFAULT_CAPACITY = 1024;

    /** RFC 3339 with microseconds, matching the events' own `occurred_at`. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * Drain passes one {@see flush()} will make.
     *
     * A flush normally makes one: it takes the buffer, writes it, and finds
     * nothing new. A second pass means writing an entry produced another
     * decision, which happens when an application's audit sink asks the Gate a
     * question on its way to disk. That is a defect rather than a workload, and
     * a ceiling is what stops it turning into a process that never returns from
     * `terminate()`. What is left over stays buffered for the next flush, so
     * the bound costs latency and not records.
     */
    private const int MAX_DRAIN_PASSES = 8;

    /** @var list<array{AuthorizationDecision, RequestContext|null}> */
    private array $buffer = [];

    /** True while {@see flush()} is draining, so it cannot be re-entered. */
    private bool $flushing = false;

    /**
     * Whether the capacity has already been reported since it was last cleared.
     *
     * The report names a deployment defect, so it is worth making loudly once
     * and not once per decision for as long as the defect lasts.
     */
    private bool $capacityReported = false;

    private readonly Randomizer $randomizer;

    private readonly int $capacity;

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ?RequestContextHolder $contextHolder = null,
        private readonly ?LoggerInterface $logger = null,
        int $capacity = self::DEFAULT_CAPACITY,
        ?Randomizer $randomizer = null,
    ) {
        // A capacity below one would hold decisions for ever. Clamping rather
        // than throwing keeps a mistyped config value from taking the boot down
        // over a number whose only sane floor is write-through.
        $this->capacity = max(1, $capacity);
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Take the decision, and return.
     *
     * This runs inside `Gate::allows()`, so what it does is capture: the
     * decision, and the request context it was made in. Nothing is chained,
     * hashed or written here on the shipped configuration — see the class
     * docblock for the two ways past the capacity, both of which are either the
     * operator's explicit choice or a reported defect.
     */
    #[Override]
    public function record(AuthorizationDecision $decision): void
    {
        $this->buffer[] = [$decision, $this->contextHolder?->tryGet()];

        if (count($this->buffer) < $this->capacity) {
            return;
        }

        // A drain running on another Fiber already has the buffer in hand and
        // will take what was appended behind it. Writing here as well would put
        // this entry ahead of ones decided before it.
        if ($this->flushing) {
            return;
        }

        if ($this->capacity > 1) {
            $this->reportCapacityReached();
        }

        /** @var array{AuthorizationDecision, RequestContext|null} $oldest */
        $oldest = array_shift($this->buffer);
        $this->write($oldest[0], $oldest[1]);
    }

    /**
     * Write every buffered decision to the audit chain.
     *
     * Safe to call when nothing is buffered, and safe to call from a flush
     * already in progress — the re-entrant call returns and the running drain
     * picks up anything the inner one appended, because the buffer is taken and
     * cleared before the first write. Bounded by {@see MAX_DRAIN_PASSES} so an
     * audit sink that produces a decision per entry cannot keep the drain
     * running for ever.
     *
     * A failure to write one entry is reported at `critical` and does not stop
     * the rest: a sink that rejects one record must not cost the trail every
     * record behind it.
     */
    #[Override]
    public function flush(): void
    {
        if ($this->flushing || $this->buffer === []) {
            return;
        }

        $this->flushing = true;
        $passes = 0;

        try {
            while ($this->buffer !== [] && $passes < self::MAX_DRAIN_PASSES) {
                $passes++;
                $pending = $this->buffer;
                $this->buffer = [];

                foreach ($pending as [$decision, $context]) {
                    $this->write($decision, $context);
                }
            }

            if ($this->buffer === []) {
                // The buffer got back to empty, so the next time it fills is a
                // new episode and worth reporting again.
                $this->capacityReported = false;
            } else {
                $this->reportCritical(
                    'Authorization decision audit flush hit its pass ceiling; writing an entry keeps producing decisions',
                    [
                        'passes' => $passes,
                        'still_buffered' => count($this->buffer),
                    ],
                );
            }
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Number of decisions captured but not yet written.
     */
    #[Override]
    public function buffered(): int
    {
        return count($this->buffer);
    }

    /**
     * Last chance to write what is still in hand.
     *
     * The terminate flush covers a served HTTP request and the queue events
     * cover a worker. Neither covers a console command, or a request served by
     * a runtime that does not call `Kernel::terminate()` -- and in PHP-FPM the
     * process ends after the response, taking anything still buffered with it.
     * Destruction is the one event all of those have in common.
     *
     * A destructor that throws during shutdown turns a clean exit into a fatal,
     * so nothing here may raise. `flush()` contains every write failure and
     * every failure to report one, and this `catch` is the backstop for
     * everything that is neither -- a `WeakMap` already torn down by the
     * shutdown sequence, an autoload that no longer resolves. Losing the last
     * entries is bad; losing the process's exit status as well is worse.
     */
    public function __destruct()
    {
        try {
            $this->flush();
        } catch (Throwable) {
            // Deliberately empty: see above.
        }
    }

    private function write(AuthorizationDecision $decision, ?RequestContext $context): void
    {
        try {
            if ($context !== null) {
                $correlationId = $context->correlationId;
                $causationId = $context->causationId;
            } else {
                // No request context: a console command, a queue worker, or a
                // decision made outside a request. The envelope still needs a
                // pair, and a fresh one says "this decision" rather than
                // borrowing an unrelated request's.
                $correlationId = CorrelationId::generate($this->randomizer);
                $causationId = CausationId::fromString($correlationId->value);
            }

            $nonce = bin2hex($this->randomizer->getBytes(16));
            $decidedAt = $decision->decidedAt();

            $envelope = $this->envelopeFor($decision, $correlationId, $causationId, $nonce, $decidedAt);

            $metadata = [
                'permission' => $decision->permission,
                'reason' => $decision->reason,
                'decision_nonce' => $nonce,
                'decided_at' => $decidedAt->format(self::TIMESTAMP_FORMAT),
                'event_id' => $envelope->eventId,
                'event_type' => $envelope->eventType,
                'event_schema_version' => $envelope->schemaVersion,
                'payload_hash' => $envelope->payloadHash,
            ];

            // Passed explicitly only when a context was actually captured: an
            // id invented here would look like the request's and correlate
            // nothing. With none, AuditLogger applies whatever context exists
            // at write time, which is the same behaviour any other audited
            // action gets.
            if ($context !== null) {
                $metadata['correlation_id'] = $context->correlationId->value;
                $metadata['causation_id'] = $context->causationId->value;
            }

            // An unauthenticated identity still produces a decision worth
            // recording, and a null actor would make the audit logger throw
            // rather than write. Name the actor explicitly instead of losing
            // the entry.
            $this->auditLogger->log(
                event: AuditEvent::Authorization,
                outcome: $decision->allowed ? AuditOutcome::Success : AuditOutcome::Denied,
                actor: $decision->identityId === ''
                    ? AuditActor::anonymous()
                    : AuditActor::user($decision->identityId),
                action: $decision->allowed ? 'authorization.granted' : 'authorization.denied',
                resource: $decision->resource ?? '',
                metadata: $metadata,
            );
        } catch (Throwable $e) {
            $this->reportCritical('Authorization decision could not be audited', [
                'permission' => $decision->permission,
                'allowed' => $decision->allowed,
                'reason' => $decision->reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Say the capacity was reached, once per episode.
     */
    private function reportCapacityReached(): void
    {
        if ($this->capacityReported) {
            return;
        }

        $this->capacityReported = true;

        $this->reportCritical(
            'Authorization decision buffer reached its capacity; a drain point should have emptied it',
            [
                'capacity' => $this->capacity,
                'hint' => 'decisions are now chained inside Gate::allows() one at a time'
                    . ' until a drain point runs',
            ],
        );
    }

    /**
     * Report at `critical` without the report becoming the failure.
     *
     * Every caller is already handling something: a write that failed, a drain
     * that would not finish, a capacity that should not have been reached. The
     * logger is application-supplied, and one that throws would replace a
     * contained fault with an uncontained one -- inside `Gate::allows()` when
     * the capacity forced a write, and inside `__destruct()` on the way out of
     * a process that was exiting cleanly.
     *
     * A failed report is dropped, because the only channel for saying "the log
     * could not be written" is the log.
     *
     * @param array<string, scalar> $context
     */
    private function reportCritical(string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        try {
            $this->logger->critical($message, $context);
        } catch (Throwable) {
            // Deliberately empty: see above.
        }
    }

    /**
     * Build the envelope whose payload hash the audit entry cites.
     *
     * The `AuthorizationGranted` / `AuthorizationDenied` events stay part of the
     * public surface and are still what a decision is expressed as. They are
     * constructed here, after the decision was returned, rather than dispatched
     * from inside it.
     */
    private function envelopeFor(
        AuthorizationDecision $decision,
        CorrelationId $correlationId,
        CausationId $causationId,
        string $nonce,
        DateTimeImmutable $decidedAt,
    ): EventEnvelope {
        $event = $decision->allowed
            ? new AuthorizationGranted(
                identityId: $decision->identityId,
                permission: $decision->permission,
                resource: $decision->resource,
                grantReason: $decision->reason,
                correlationId: $correlationId->value,
                nonce: $nonce,
                occurredAt: $decidedAt,
            )
            : new AuthorizationDenied(
                identityId: $decision->identityId,
                permission: $decision->permission,
                resource: $decision->resource,
                denialReason: $decision->reason,
                correlationId: $correlationId->value,
                nonce: $nonce,
                occurredAt: $decidedAt,
            );

        return EventEnvelope::wrap(
            eventType: $event::class,
            schemaVersion: $decision->allowed
                ? AuthorizationGranted::SCHEMA_VERSION
                : AuthorizationDenied::SCHEMA_VERSION,
            payload: $event->toArray(),
            metadata: new EventMetadata(
                correlationId: $correlationId,
                causationId: $causationId,
                actor: $decision->identityId === '' ? null : $decision->identityId,
                occurredAt: $decidedAt,
            ),
            randomizer: $this->randomizer,
        );
    }
}
