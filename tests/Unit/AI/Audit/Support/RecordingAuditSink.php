<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit\Support;

use Override;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditSinkInterface;

/**
 * Keeps every entry a real {@see \Pulsar\Security\Audit\AuditLogger} wrote.
 *
 * The tests drive the REAL logger rather than a mock of
 * {@see \Pulsar\Audit\AuditLoggerInterface}, because half of what they check —
 * that a call produces exactly one CHAINED entry, and that the chain still
 * verifies afterwards — is a property of the chain and not of the call.
 */
final class RecordingAuditSink implements AuditSinkInterface
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    #[Override]
    public function write(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}
