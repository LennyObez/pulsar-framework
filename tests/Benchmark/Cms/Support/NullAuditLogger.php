<?php

declare(strict_types=1);

namespace Pulsar\Tests\Benchmark\Cms\Support;

use DateTimeImmutable;
use Override;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * No-op audit logger for benchmark scenarios.
 *
 * Returns a minimal AuditEntry without HMAC computation to avoid
 * crypto overhead in performance measurements.
 */
final readonly class NullAuditLogger implements AuditLoggerInterface
{
    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        ?string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        return new AuditEntry(
            id: 'bench-audit-noop',
            event: $event,
            outcome: $outcome,
            actor: $actor ?? 'system',
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable(),
            metadata: $metadata,
            previousHmac: '',
            hmac: '',
        );
    }
}
