<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Probe;

use NoDiscard;
use Override;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ObservationId;

/**
 * Whether the AI management system record is documented information or a PHP
 * array.
 *
 * ISO 42001:2023 Clause 7.5 asks for documented information, and Annex A.10 asks
 * for the AI systems an organisation develops or uses to be documented. Both were
 * carried by {@see AiModelRegistryProbe}, which asks which class answered
 * `AiModelRegistryInterface` — and the class the extension shipped was
 * `InMemoryModelRegistry`. A record that a second reader cannot see is not
 * documented, so the two documentation controls rested on the existence of an
 * object rather than on the existence of a record.
 *
 * This probe reads {@see ObservationId::AiGovernanceRecordsDurable}, which is
 * produced by writing a model, an impact assessment, a data quality report and an
 * explanation and reading each one back through a SECOND store instance. See
 * {@see \Pulsar\Compliance\Evidence\AiGovernanceRecordObserver}.
 *
 * IT IS DELIBERATELY NOT WHAT `ai-act-art-5-enforcement` READS.
 * {@see AiModelRegistryProbe} still carries that control, and it should: what
 * Article 5 enforcement turns on is a REFUSAL — the registry declining to put a
 * prohibited practice into production — and that refusal is performed correctly by
 * an in-memory registry. Requiring durability of a control about a refusal would
 * fail a deployment whose mechanism works, which is the mirror image of the defect
 * this probe exists to fix.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class AiGovernanceRecordProbe extends CapabilityProbe
{
    #[Override]
    #[NoDiscard]
    public function id(): string
    {
        return 'probe.ai_governance_record';
    }

    #[Override]
    #[NoDiscard]
    public function describe(): string
    {
        return 'Whether the AI system inventory, impact assessments, data governance record and '
            . 'decision explanations survive the process that wrote them.';
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
            ObservationId::AiImpactAssessmentResolved,
            ObservationId::AiDataGovernanceResolved,
            ObservationId::AiExplainabilityResolved,
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
            'Point every ai_governance store key at a durable implementation. The extension '
                . 'ships one for each — set registry_store, impact_assessment_store, '
                . 'data_governance_store and explainability_store to "database" in '
                . 'config/ai-governance.php, which is the default, and run `pulsar migrate`.',
            'If a store is configured by class name, this check cannot construct a second '
                . 'instance of it and reports so rather than assuming: evidence the durability of '
                . 'that store separately, or point the key at the shipped implementation.',
            'If this deployment operates no AI system, remove Iso42001 from enabled_frameworks '
                . 'rather than leaving its documentation clauses claimed and unobserved.',
        ];
    }
}
