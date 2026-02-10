<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Support;

use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditSinkInterface;
use Pulsar\Security\Audit\ChainableAuditSinkInterface;

/**
 * In-memory audit sink for Tier A benchmarks.
 *
 * Stores entries in an array. Supports chain resumption via lastHmac().
 */
final class InMemoryAuditSink implements AuditSinkInterface, ChainableAuditSinkInterface
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
