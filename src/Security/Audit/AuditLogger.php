<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use DateTimeImmutable;
use Fiber;
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

    /** Cooperative fiber mutex for HMAC chain integrity. */
    private bool $chainLocked = false;

    /**
     * @throws SodiumException
     */
    private readonly Randomizer $randomizer;

    /**
     * @throws SecurityException When the sink reports
     *                           {@see AuditChainState::Corrupted}: the
     *                           chain cannot be safely resumed and we
     *                           refuse to silently re-seed over the
     *                           corrupt state (F24.3).
     * @throws SodiumException
     */
    public function __construct(
        private readonly AuditSinkInterface $sink,
        private readonly string $auditKey,
        ?Randomizer $randomizer = null,
        private readonly ?RequestContextHolder $contextHolder = null,
    ) {
        $this->previousHmac = Hmac::computeHex(self::SEED_MESSAGE, $this->auditKey);

        // F24.3: state-aware sinks distinguish "empty, fresh chain"
        // from "non-empty but unreadable" — fail closed on corruption
        // so a tamper-evident chain cannot silently restart from the
        // seed after a truncated or malformed last entry.
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
            // Legacy contract: a sink that doesn't implement
            // AuditChainStateAware can't distinguish corruption from
            // emptiness, so a null lastHmac falls back to the seed
            // (and forfeits the corruption-detection guarantee).
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
     * `'system'` actor was historically used to mask null actors and is now
     * forbidden (F25.10): an `AuditActorMissingException` is raised instead.
     * Always enriches metadata with correlation_id and causation_id when
     * context is available.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws AuditActorMissingException when no actor can be resolved.
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

            $enrichedMetadata['correlation_id'] ??= $requestContext->correlationId->value;
            $enrichedMetadata['causation_id'] ??= $requestContext->causationId->value;
        }

        if ($resolvedActor === null || $resolvedActor === '') {
            throw AuditActorMissingException::notProvidedAndNoContext($action);
        }

        $id = bin2hex($this->randomizer->getBytes(16));
        $timestamp = new DateTimeImmutable();

        // Acquire cooperative mutex: suspend fiber until the chain is unlocked.
        // Only suspend when running inside a Fiber; main-thread calls are inherently serial.
        while ($this->chainLocked && Fiber::getCurrent() !== null) {
            Fiber::suspend();
        }
        $this->chainLocked = true;

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
            $this->chainLocked = false;
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
