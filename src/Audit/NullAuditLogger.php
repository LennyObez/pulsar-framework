<?php

declare(strict_types=1);

namespace Pulsar\Audit;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * No-op audit logger for environments without audit infrastructure.
 *
 * Used as a fallback when AuditLoggerInterface has no real implementation
 * registered, allowing security-critical services like SafeHtmlPolicy to
 * function without a full audit chain. Events are silently discarded.
 *
 * SEC-AUDIT-01: production deployments are refused via
 * {@see \Pulsar\Deploy\Check\AuditLoggerReadinessCheck} — silent audit
 * loss in regulated environments (PCI Req 10, HIPAA §164.312(b), SOX ITGC,
 * GDPR Art 30) is not acceptable. Wire AuditLogger (HMAC chain + persistent
 * sink) in your composition root before deploying.
 */
#[Api(since: '1.0.0')]
final readonly class NullAuditLogger implements AuditLoggerInterface
{
    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $resolved = match (true) {
            $actor instanceof AuditActor => $actor->id,
            $actor === null || $actor === '' => 'null',
            default => $actor,
        };

        return new AuditEntry(
            id: 'null',
            event: $event,
            outcome: $outcome,
            actor: $resolved,
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable(),
            metadata: $metadata,
            previousHmac: '',
            hmac: '',
        );
    }
}
