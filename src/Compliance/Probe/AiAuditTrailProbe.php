<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether AI events reach the same tamper-evident trail as everything else.
 *
 * Requires the chain to verify, not merely the AI logger to exist: an AI audit
 * log that cannot be shown intact is a log, not an audit trail.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiAuditTrailProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_audit_trail';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether an AI audit logger resolved and the underlying HMAC chain still verifies.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::AiGovernanceExtensionActive,
            ObservationId::AiAuditLoggerResolved,
            ObservationId::AuditChainVerified,
        ];
    }

    /**
     * @return list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function supporting(): array
    {
        return [
            ObservationId::AuditSinkResolved,
        ];
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    #[Override]
    #[NoDiscard]
    protected function remediations(): array
    {
        return [
            'Install and enable the pulsar/ai-governance extension.',
            'Bind a persisting AuditSinkInterface and set PULSAR_MASTER_KEY so the AI '
                . 'events land in a chain that can be verified.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks.',
        ];
    }
}
