<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

/**
 * Decorates a data-governance store to enforce that consent is recorded for
 * all training data, per AiGovernanceConfig::$requireConsentForTrainingData.
 *
 * ISO 42001:2023 Clause 8.3 requires appropriate consent for AI training
 * data. Provenance recorded without consent is rejected at the boundary
 * rather than silently accepted, so the inert config toggle becomes a real
 * guarantee. All other operations delegate to the wrapped store unchanged.
 */
#[Internal(reason: 'Decorator wiring; use AiDataGovernanceInterface for access')]
final readonly class ConsentEnforcingDataGovernance implements AiDataGovernanceInterface
{
    public function __construct(
        private AiDataGovernanceInterface $inner,
    ) {}

    #[Override]
    public function recordProvenance(DataProvenance $provenance): void
    {
        if (! $provenance->consentObtained) {
            throw AiGovernanceException::consentRequired($provenance->datasetId);
        }

        $this->inner->recordProvenance($provenance);
    }

    #[Override]
    public function getProvenance(string $datasetId): array
    {
        return $this->inner->getProvenance($datasetId);
    }

    #[Override]
    public function recordQualityReport(DataQualityReport $report): void
    {
        $this->inner->recordQualityReport($report);
    }

    #[Override]
    public function getLatestQualityReport(string $datasetId): ?DataQualityReport
    {
        return $this->inner->getLatestQualityReport($datasetId);
    }

    #[Override]
    public function isConsentComplete(string $datasetId): bool
    {
        return $this->inner->isConsentComplete($datasetId);
    }
}
