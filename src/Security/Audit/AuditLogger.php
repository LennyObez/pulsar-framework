<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use function bin2hex;

use DateTimeImmutable;
use JsonException;
use Pulsar\Security\Crypto\Hmac;

use function random_bytes;

/**
 * Orchestrates tamper-evident audit logging with HMAC chain.
 *
 * Tracks the previousHmac state across entries to maintain the chain.
 * The seed HMAC is computed from a well-known string and the audit key,
 * so verification tools can reconstruct the chain from the beginning.
 */
final class AuditLogger
{
    /**
     * Seed message used to compute the initial chain HMAC.
     */
    private const string SEED_MESSAGE = 'PULSAR_AUDIT_SEED';

    private string $previousHmac;

    public function __construct(
        private readonly AuditSinkInterface $sink,
        private readonly string $auditKey,
    ) {
        $this->previousHmac = Hmac::computeHex(self::SEED_MESSAGE, $this->auditKey);
    }

    /**
     * Log an audit event.
     *
     * Creates an AuditEntry with HMAC chain, writes it to the sink,
     * and advances the chain state.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws \Random\RandomException
     * @throws JsonException
     */
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $id = bin2hex(random_bytes(16));
        $timestamp = new DateTimeImmutable();

        $entry = AuditEntry::create(
            id: $id,
            event: $event,
            outcome: $outcome,
            actor: $actor,
            action: $action,
            resource: $resource,
            timestamp: $timestamp,
            metadata: $metadata,
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
