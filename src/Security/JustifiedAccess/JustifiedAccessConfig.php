<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

use function array_map;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for the Justified Access Engine.
 */
#[Api(since: '1.0.0')]
final readonly class JustifiedAccessConfig
{
    /**
     * @param list<JustificationCategory> $defaultCategories Available justification categories
     * @param list<DataClassification> $requireSupervisorFor Classifications requiring supervisor approval
     */
    public function __construct(
        public bool $enabled = true,
        public array $defaultCategories = [
            JustificationCategory::CustomerRequest,
            JustificationCategory::RegulatoryObligation,
            JustificationCategory::InternalAudit,
            JustificationCategory::DisputeResolution,
            JustificationCategory::AccountMaintenance,
        ],
        public array $requireSupervisorFor = [],
        public int $breakTheGlassDuration = 900,
        public int $anomalyThreshold = 50,
        public int $anomalyWindowSeconds = 3600,
        public string $justificationHeader = 'X-Access-Justification',
        public string $categoryHeader = 'X-Access-Justification-Category',
        public int $minJustificationLength = 10,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaultCategories = null;
        if (is_array($data['default_categories'] ?? null)) {
            $defaultCategories = array_values(array_map(
                static fn(mixed $v): JustificationCategory => JustificationCategory::from(is_string($v) ? $v : 'customer_request'),
                $data['default_categories'],
            ));
        }

        $requireSupervisorFor = null;
        if (is_array($data['require_supervisor_for'] ?? null)) {
            $requireSupervisorFor = array_values(array_map(
                static fn(mixed $v): DataClassification => $v instanceof DataClassification ? $v : DataClassification::from(is_string($v) ? $v : 'restricted'),
                $data['require_supervisor_for'],
            ));
        }

        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : true,
            defaultCategories: $defaultCategories ?? [
                JustificationCategory::CustomerRequest,
                JustificationCategory::RegulatoryObligation,
                JustificationCategory::InternalAudit,
                JustificationCategory::DisputeResolution,
                JustificationCategory::AccountMaintenance,
            ],
            requireSupervisorFor: $requireSupervisorFor ?? [],
            breakTheGlassDuration: is_int($data['break_the_glass_duration'] ?? null) ? $data['break_the_glass_duration'] : 900,
            anomalyThreshold: is_int($data['anomaly_threshold'] ?? null) ? $data['anomaly_threshold'] : 50,
            anomalyWindowSeconds: is_int($data['anomaly_window_seconds'] ?? null) ? $data['anomaly_window_seconds'] : 3600,
            justificationHeader: is_string($data['justification_header'] ?? null) ? $data['justification_header'] : 'X-Access-Justification',
            categoryHeader: is_string($data['category_header'] ?? null) ? $data['category_header'] : 'X-Access-Justification-Category',
            minJustificationLength: is_int($data['min_justification_length'] ?? null) ? $data['min_justification_length'] : 10,
        );
    }
}
