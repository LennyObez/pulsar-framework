<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;

/**
 * Contract for AI training data governance.
 *
 * ISO 42001:2023 Clause 8.3 requires organizations to manage data quality,
 * track data provenance, and ensure appropriate consent for AI training data.
 * @api
 */
#[Api(since: '1.0.0')]
interface AiDataGovernanceInterface
{
    /**
     * Record the provenance of a training dataset.
     */
    public function recordProvenance(DataProvenance $provenance): void;

    /**
     * Retrieve provenance records for a dataset.
     *
     * @return list<DataProvenance>
     */
    #[NoDiscard]
    public function getProvenance(string $datasetId): array;

    /**
     * Store a data quality assessment report.
     */
    public function recordQualityReport(DataQualityReport $report): void;

    /**
     * Retrieve the latest quality report for a dataset.
     */
    #[NoDiscard]
    public function getLatestQualityReport(string $datasetId): ?DataQualityReport;

    /**
     * Check whether consent has been obtained for all provenance records of a dataset.
     */
    #[NoDiscard]
    public function isConsentComplete(string $datasetId): bool;
}
