<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Dto;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Tracks the origin and chain of custody for AI training data.
 *
 * ISO 42001:2023 Clause 8.3 requires organizations to manage data quality
 * and document the provenance of data used in AI system development.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DataProvenance
{
    /**
     * @param non-empty-string $id Unique provenance record identifier
     * @param non-empty-string $datasetId Identifier of the dataset
     * @param non-empty-string $source Origin of the data (URL, vendor, internal system)
     * @param non-empty-string $dataType Type of data (text, images, structured, etc.)
     * @param bool $consentObtained Whether consent was obtained for AI training use
     * @param non-empty-string|null $consentReference Reference to consent record if applicable
     * @param non-empty-string|null $license License governing the data's use
     * @param list<non-empty-string> $transformations Processing steps applied to the data
     * @param array<string, mixed> $qualityMetrics Data quality assessment metrics
     */
    public function __construct(
        public string $id,
        public string $datasetId,
        public string $source,
        public string $dataType,
        public DateTimeImmutable $collectedAt,
        public bool $consentObtained = false,
        public ?string $consentReference = null,
        public ?string $license = null,
        public array $transformations = [],
        public array $qualityMetrics = [],
    ) {}
}
