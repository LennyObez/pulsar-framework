<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Pulsar\Security\Audit\AuditChainState;
use Pulsar\Security\Audit\AuditChainStateAware;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Audit\ChainableAuditSinkInterface;

/**
 * In-memory audit sink for Tier A benchmarks.
 *
 * Stores entries in an array. Supports chain resumption via lastHmac()
 * and chainState() — the in-memory store is corruption-free by
 * construction so chainState only ever returns Empty or Healthy.
 */
final class InMemoryAuditSink implements AuditSinkInterface, ChainableAuditSinkInterface, AuditChainStateAware
{
    /** @var list<AuditEntry> */
    private array $entries = [];

    public function write(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function lastHmac(): ?string
    {
        if ($this->entries === []) {
            return null;
        }

        return $this->entries[array_key_last($this->entries)]->hmac;
    }

    public function chainState(): AuditChainState
    {
        return $this->entries === [] ? AuditChainState::Empty : AuditChainState::Healthy;
    }

    /**
     * @return list<AuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
