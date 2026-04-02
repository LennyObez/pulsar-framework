<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

use function array_map;
use function array_values;
use function is_string;

/**
 * Configuration for the Justified Access Engine.
 * @api
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
     * @param array{
     *     enabled?: bool,
     *     default_categories?: list<JustificationCategory|string>,
     *     require_supervisor_for?: list<DataClassification|string>,
     *     break_the_glass_duration?: int,
     *     anomaly_threshold?: int,
     *     anomaly_window_seconds?: int,
     *     justification_header?: string,
     *     category_header?: string,
     *     min_justification_length?: int,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $defaultCategories = isset($data['default_categories'])
            ? array_values(array_map(
                static fn(JustificationCategory|string $v): JustificationCategory => $v instanceof JustificationCategory
                    ? $v
                    : JustificationCategory::from(is_string($v) ? $v : 'customer_request'),
                $data['default_categories'],
            ))
            : [
                JustificationCategory::CustomerRequest,
                JustificationCategory::RegulatoryObligation,
                JustificationCategory::InternalAudit,
                JustificationCategory::DisputeResolution,
                JustificationCategory::AccountMaintenance,
            ];

        $requireSupervisorFor = isset($data['require_supervisor_for'])
            ? array_values(array_map(
                static fn(DataClassification|string $v): DataClassification => $v instanceof DataClassification
                    ? $v
                    : DataClassification::from(is_string($v) ? $v : 'restricted'),
                $data['require_supervisor_for'],
            ))
            : [];

        return new self(
            enabled: $data['enabled'] ?? true,
            defaultCategories: $defaultCategories,
            requireSupervisorFor: $requireSupervisorFor,
            breakTheGlassDuration: $data['break_the_glass_duration'] ?? 900,
            anomalyThreshold: $data['anomaly_threshold'] ?? 50,
            anomalyWindowSeconds: $data['anomaly_window_seconds'] ?? 3600,
            justificationHeader: $data['justification_header'] ?? 'X-Access-Justification',
            categoryHeader: $data['category_header'] ?? 'X-Access-Justification-Category',
            minJustificationLength: $data['min_justification_length'] ?? 10,
        );
    }
}
