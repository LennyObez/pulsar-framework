<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\Exception\AuditActorMissingException;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Exception\SecurityException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use SodiumException;

use function bin2hex;

/**
 * Orchestrates tamper-evident audit logging with HMAC chain.
 *
 * Tracks the previousHmac state across entries to maintain the chain.
 * The seed HMAC is computed from a well-known string and the audit key,
 * so verification tools can reconstruct the chain from the beginning.
 *
 * When a RequestContextHolder is available, auto-enriches entries with
 * correlation/causation IDs and auto-fills actor from context.
 * @api
 */
#[Api(since: '1.0.0')]
final class AuditLogger implements AuditLoggerInterface
{
    /**
     * Seed message used to compute the initial chain HMAC.
     */
    private const string SEED_MESSAGE = 'PULSAR_AUDIT_SEED';

    private string $previousHmac;

    /**
     * Raised for exactly as long as one call is between reading
     * {@see previousHmac} and replacing it.
     *
     * A second entrant inside that window — a nested {@see log()} from an audit
     * sink, a listener the sink reaches, or a fiber resumed while the first is
     * parked mid-write — reads the same predecessor and writes a second entry
     * claiming the same position in the chain. One of the two is broken forever
     * after, and a chain verifier reports the file as tampered with.
     *
     * This used to be a spin on a bare `Fiber::suspend()`, guarded by
     * `Fiber::getCurrent() !== null`, which failed in both directions. A caller
     * on the main thread skipped the wait entirely and walked straight into the
     * window it was supposed to be excluded from, so the case most likely to
     * actually occur — a sink that logs — was not covered at all. And a caller
     * inside a fiber suspended with no value, which is the protocol
     * {@see \Pulsar\Runtime\Fiber\FiberScheduler} reads as "resume me when my
     * connection socket becomes readable": the wait was answered by an unrelated
     * event, or by nothing, on the tamper-evidence mechanism of a compliance
     * framework. Waiting cannot be made correct here in any case. There is no
     * execution context that both holds this flag and can be resumed by the code
     * that is waiting on it — the waiter does not know which scheduler, if any,
     * is driving the holder, or what protocol would wake it. Under the execution
     * model of ADR-0071 a second entrant is always a defect, so it is refused,
     * loudly and immediately, rather than parked on a condition nothing may ever
     * satisfy.
     *
     * Deliberately one flag on the instance, and not fiber-keyed the way
     * {@see \Pulsar\Auth\Authorization\Gate} keys its recording state per ADR-0057.
     * The Gate asks "is THIS call stack inside its own record", which an
     * instance-wide flag answers wrongly the moment two fibers exist. The question
     * here is "is ANYONE between reading the head and replacing it", because there
     * is one head per logger and two writers must not share it — and that is what
     * an instance-wide flag answers exactly.
     */
    private bool $advancingChain = false;

    private readonly Randomizer $randomizer;

    /**
     * @throws SecurityException When the sink reports
     *                           {@see AuditChainState::Corrupted}: the
     *                           chain cannot be safely resumed, and
     *                           re-seeding over corrupt state would
     *                           forge a fresh-looking chain.
     * @throws InvalidArgumentException When the audit key is shorter than
     *                                  the libsodium minimum and the seed
     *                                  HMAC cannot be computed.
     * @throws SodiumException
     */
    public function __construct(
        private readonly AuditSinkInterface $sink,
        private readonly string $auditKey,
        ?Randomizer $randomizer = null,
        private readonly ?RequestContextHolder $contextHolder = null,
    ) {
        $this->previousHmac = Hmac::computeHex(self::SEED_MESSAGE, $this->auditKey);

        // State-aware sinks distinguish "empty, fresh chain" from
        // "non-empty but unreadable" — fail closed on corruption so a
        // tamper-evident chain cannot restart from the seed after a
        // truncated or malformed last entry.
        if ($sink instanceof AuditChainStateAware) {
            $state = $sink->chainState();

            if ($state === AuditChainState::Corrupted) {
                throw SecurityException::auditChainCorrupted(
                    'audit sink reports unreadable last entry — see error_log for diagnostics',
                );
            }

            if ($state === AuditChainState::Healthy) {
                $lastHmac = $sink->lastHmac();

                if ($lastHmac !== null) {
                    $this->previousHmac = $lastHmac;
                }
            }
            // AuditChainState::Empty -> keep the seed previousHmac.
        } elseif ($sink instanceof ChainableAuditSinkInterface) {
            // A sink that doesn't implement AuditChainStateAware cannot
            // distinguish corruption from emptiness, so a null lastHmac
            // falls back to the seed — and forfeits the
            // corruption-detection guarantee.
            $lastHmac = $sink->lastHmac();

            if ($lastHmac !== null) {
                $this->previousHmac = $lastHmac;
            }
        }

        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Log an audit event.
     *
     * Creates an AuditEntry with HMAC chain, writes it to the sink,
     * and advances the chain state. The actor MUST resolve to a non-empty
     * identifier — either passed explicitly (as `AuditActor` or string) or
     * derived from the active `RequestContext`. Falling back to a generic
     * `'system'` actor is forbidden — it masks a missing actor and makes
     * the record useless as evidence; an `AuditActorMissingException` is
     * raised instead.
     * Always enriches metadata with correlation_id and causation_id when
     * context is available.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws AuditActorMissingException when no actor can be resolved.
     * @throws SecurityException when a second call reaches the chain advance
     *                           while one is still inside it — see
     *                           {@see $advancingChain}
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $resolvedActor = match (true) {
            $actor instanceof AuditActor => $actor->id,
            $actor === '' => null,
            default => $actor,
        };
        $enrichedMetadata = $metadata;

        // Auto-enrich from request context when available
        $requestContext = $this->contextHolder?->tryGet();

        if ($requestContext !== null) {
            if ($resolvedActor === null) {
                $resolvedActor = $requestContext->actor;
            }

            if (!isset($enrichedMetadata['correlation_id'])) {
                $enrichedMetadata['correlation_id'] = $requestContext->correlationId->value;
            }
            if (!isset($enrichedMetadata['causation_id'])) {
                $enrichedMetadata['causation_id'] = $requestContext->causationId->value;
            }
        }

        if ($resolvedActor === null || $resolvedActor === '') {
            throw AuditActorMissingException::notProvidedAndNoContext($action);
        }

        $id = bin2hex($this->randomizer->getBytes(16));
        $timestamp = new DateTimeImmutable();

        // Exclusive for the length of the chain advance. See $advancingChain for
        // why a second entrant is refused instead of made to wait.
        if ($this->advancingChain) {
            throw SecurityException::auditChainAdvanceReentered($action);
        }

        $this->advancingChain = true;

        try {
            $entry = AuditEntry::create(
                id: $id,
                event: $event,
                outcome: $outcome,
                actor: $resolvedActor,
                action: $action,
                resource: $resource,
                timestamp: $timestamp,
                metadata: $enrichedMetadata,
                previousHmac: $this->previousHmac,
                auditKey: $this->auditKey,
            );

            $this->sink->write($entry);
            $this->previousHmac = $entry->hmac;
        } finally {
            $this->advancingChain = false;
        }

        return $entry;
    }

    /**
     * Get the current chain HMAC (the HMAC of the last written entry).
     */
    public function previousHmac(): string
    {
        return $this->previousHmac;
    }
}
