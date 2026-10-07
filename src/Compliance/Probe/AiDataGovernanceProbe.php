<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether training-data provenance, quality and consent are tracked by something
 * that exists in this deployment.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiDataGovernanceProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_data_governance';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AI governance extension is active and a data-governance implementation resolved.';
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
            ObservationId::AiDataGovernanceResolved,
            // Added in rc.12. Provenance, quality and the basis on which data was
            // obtained are all statements about how a dataset came to be, and a
            // store that loses them at the next restart answers "unknown" to
            // every one of them. The resolved fact says a store is bound; this
            // one says a record written through it is still there when a
            // different instance looks.
            ObservationId::AiGovernanceRecordsDurable,
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
            ObservationId::ConsentSubsystemResolved,
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
            'Install and enable the pulsar/ai-governance extension and bind an '
                . 'AiDataGovernanceInterface implementation.',
            'Point the ai_governance store keys at a durable implementation. The extension ships '
                . 'one for each store — "database" is the default in config/ai-governance.php since '
                . 'rc.12 — and `pulsar migrate` creates the tables. An in-memory store loses the '
                . 'record at the next restart, which is a gap this check reports rather than '
                . 'assumes.',
            'If this deployment operates no AI system, remove Iso42001 from '
                . 'enabled_frameworks.',
        ];
    }
}
