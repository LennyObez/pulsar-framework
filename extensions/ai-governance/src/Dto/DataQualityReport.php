<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Report on the quality assessment of a training dataset.
 *
 * ISO 42001:2023 Clause 8.3 requires data quality management for AI systems.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DataQualityReport
{
    /**
     * @param non-empty-string $datasetId Identifier of the assessed dataset
     * @param float $completeness Percentage of non-null values (0.0-100.0)
     * @param float $accuracy Assessed accuracy of the data (0.0-100.0)
     * @param float $consistency Consistency score across the dataset (0.0-100.0)
     * @param int $totalRecords Total number of records in the dataset
     * @param int $invalidRecords Number of records flagged as invalid
     * @param list<non-empty-string> $issues Identified data quality issues
     */
    public function __construct(
        public string $datasetId,
        public DateTimeImmutable $assessedAt,
        public float $completeness,
        public float $accuracy,
        public float $consistency,
        public int $totalRecords,
        public int $invalidRecords,
        public array $issues = [],
    ) {}

    /**
     * Compute an overall quality score as the weighted average of metrics.
     */
    public function overallScore(): float
    {
        return round(($this->completeness + $this->accuracy + $this->consistency) / 3.0, 2);
    }
}
