<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use function bin2hex;

use DateTimeImmutable;
use JsonException;
use Override;
use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Security\Crypto\Hmac;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use SodiumException;

/**
 * Orchestrates tamper-evident audit logging with HMAC chain.
 *
 * Tracks the previousHmac state across entries to maintain the chain.
 * The seed HMAC is computed from a well-known string and the audit key,
 * so verification tools can reconstruct the chain from the beginning.
 *
 * When a RequestContextHolder is available, auto-enriches entries with
 * correlation/causation IDs and auto-fills actor from context.
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
     * @throws SodiumException
     */
    private readonly Randomizer $randomizer;

    /**
     * @throws SodiumException
     */
    public function __construct(
        private readonly AuditSinkInterface $sink,
        private readonly string $auditKey,
        ?Randomizer $randomizer = null,
        private readonly ?RequestContextHolder $contextHolder = null,
    ) {
        $this->previousHmac = Hmac::computeHex(self::SEED_MESSAGE, $this->auditKey);

        // Resume chain from the last entry if the sink supports it
        if ($sink instanceof ChainableAuditSinkInterface) {
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
     * and advances the chain state. When actor is null and RequestContext
     * is available, auto-fills from context. Always enriches metadata
     * with correlation_id and causation_id when context is available.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        ?string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $resolvedActor = $actor;
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

        $id = bin2hex($this->randomizer->getBytes(16));
        $timestamp = new DateTimeImmutable();

        $entry = AuditEntry::create(
            id: $id,
            event: $event,
            outcome: $outcome,
            actor: $resolvedActor ?? 'system',
            action: $action,
            resource: $resource,
            timestamp: $timestamp,
            metadata: $enrichedMetadata,
            previousHmac: $this->previousHmac,
            auditKey: $this->auditKey,
        );

        $this->sink->write($entry);
        $this->previousHmac = $entry->hmac;

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
