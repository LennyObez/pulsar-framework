<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

use function bin2hex;

use DateTimeImmutable;
use JsonException;
use Pulsar\Api\Api;
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
 */
#[Api]
final class AuditLogger
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
    ) {
        $this->previousHmac = Hmac::computeHex(self::SEED_MESSAGE, $this->auditKey);
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Log an audit event.
     *
     * Creates an AuditEntry with HMAC chain, writes it to the sink,
     * and advances the chain state.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $id = bin2hex($this->randomizer->getBytes(16));
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
