<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether an AI impact assessment capability is in service, not merely specified.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiImpactAssessmentProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_impact_assessment';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AI governance extension is active and an impact-assessment implementation resolved.';
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
            ObservationId::AiImpactAssessmentResolved,
            // Added in rc.12. An impact assessment is a claim about the past, and
            // Clause 6.1.2 and Clause 8.2 both ask for it to have been performed
            // — not for a store that could hold one. The resolved fact above
            // cannot tell an assessment recorded last quarter from one recorded
            // in this request by a store that forgets, and this one can: it is
            // produced by opening an assessment and reading it back through a
            // store instance that did not open it.
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
            ObservationId::AiModelRegistryResolved,
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
                . 'AiImpactAssessmentInterface implementation.',
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
