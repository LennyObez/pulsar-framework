<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether anything is actually watching this deployment.
 *
 * The health checks are EXECUTED during evidence gathering, not counted, and
 * the trace exporter must be one that sends spans off the box. A monitoring
 * control satisfied by a registered-but-never-run check, or by a
 * NoopSpanProcessor, is the ADR-0041 defect wearing a different hat.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class ContinuousMonitoringProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.continuous_monitoring';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the registered health checks pass when executed and traces leave the process.';
    }

    /**
     * @return non-empty-list<ObservationId>
     */
    #[Override]
    #[NoDiscard]
    protected function required(): array
    {
        return [
            ObservationId::HealthChecksExecuted,
            ObservationId::ObservabilityExporterResolved,
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
            'Register health checks that cover the subsystems this control is about.',
            'Install and configure a trace exporter that ships spans off the box; '
                . 'NoopSpanProcessor discards them and InMemorySpanCollector never sends '
                . 'them.',
        ];
    }
}
