<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;

/**
 * In-memory data governance store for development and testing.
 */
#[Internal(reason: 'Development store; production deployments should use a persistent implementation')]
final class InMemoryDataGovernanceStore implements AiDataGovernanceInterface
{
    /** @var array<string, list<DataProvenance>> Keyed by dataset ID */
    private array $provenance = [];

    /** @var array<string, DataQualityReport> Latest report keyed by dataset ID */
    private array $qualityReports = [];

    #[Override]
    public function recordProvenance(DataProvenance $provenance): void
    {
        if (! isset($this->provenance[$provenance->datasetId])) {
            $this->provenance[$provenance->datasetId] = [];
        }

        $this->provenance[$provenance->datasetId][] = $provenance;
    }

    #[Override]
    public function getProvenance(string $datasetId): array
    {
        return $this->provenance[$datasetId] ?? [];
    }

    #[Override]
    public function recordQualityReport(DataQualityReport $report): void
    {
        $this->qualityReports[$report->datasetId] = $report;
    }

    #[Override]
    public function getLatestQualityReport(string $datasetId): ?DataQualityReport
    {
        return $this->qualityReports[$datasetId] ?? null;
    }

    #[Override]
    public function isConsentComplete(string $datasetId): bool
    {
        $records = $this->provenance[$datasetId] ?? [];

        if ($records === []) {
            return false;
        }

        foreach ($records as $record) {
            if (! $record->consentObtained) {
                return false;
            }
        }

        return true;
    }
}
