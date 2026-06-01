<?php

declare(strict_types=1);

namespace Pulsar\Security\JustifiedAccess;

use Pulsar\Api\Api;
use Pulsar\Security\Compliance\DataClassification;

use function array_map;
use function array_values;
use function is_array;
use function is_bool;
use function is_int;
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
     * Typed loosely because the input is a config file loaded by users;
     * each access is validated or coerced below.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var mixed $rawDefaultCategories */
        $rawDefaultCategories = $data['default_categories'] ?? null;
        $defaultCategories = is_array($rawDefaultCategories)
            ? array_values(array_map(
                static function (mixed $v): JustificationCategory {
                    if ($v instanceof JustificationCategory) {
                        return $v;
                    }

                    return JustificationCategory::from(is_string($v) ? $v : 'customer_request');
                },
                $rawDefaultCategories,
            ))
            : [
                JustificationCategory::CustomerRequest,
                JustificationCategory::RegulatoryObligation,
                JustificationCategory::InternalAudit,
                JustificationCategory::DisputeResolution,
                JustificationCategory::AccountMaintenance,
            ];

        /** @var mixed $rawRequireSupervisor */
        $rawRequireSupervisor = $data['require_supervisor_for'] ?? null;
        $requireSupervisorFor = is_array($rawRequireSupervisor)
            ? array_values(array_map(
                static function (mixed $v): DataClassification {
                    if ($v instanceof DataClassification) {
                        return $v;
                    }

                    return DataClassification::from(is_string($v) ? $v : 'restricted');
                },
                $rawRequireSupervisor,
            ))
            : [];

        /** @var mixed $enabled */
        $enabled = $data['enabled'] ?? true;
        /** @var mixed $break */
        $break = $data['break_the_glass_duration'] ?? 900;
        /** @var mixed $anomalyThreshold */
        $anomalyThreshold = $data['anomaly_threshold'] ?? 50;
        /** @var mixed $anomalyWindow */
        $anomalyWindow = $data['anomaly_window_seconds'] ?? 3600;
        /** @var mixed $justificationHeader */
        $justificationHeader = $data['justification_header'] ?? 'X-Access-Justification';
        /** @var mixed $categoryHeader */
        $categoryHeader = $data['category_header'] ?? 'X-Access-Justification-Category';
        /** @var mixed $minJustification */
        $minJustification = $data['min_justification_length'] ?? 10;

        return new self(
            enabled: is_bool($enabled) ? $enabled : true,
            defaultCategories: $defaultCategories,
            requireSupervisorFor: $requireSupervisorFor,
            breakTheGlassDuration: is_int($break) ? $break : 900,
            anomalyThreshold: is_int($anomalyThreshold) ? $anomalyThreshold : 50,
            anomalyWindowSeconds: is_int($anomalyWindow) ? $anomalyWindow : 3600,
            justificationHeader: is_string($justificationHeader) ? $justificationHeader : 'X-Access-Justification',
            categoryHeader: is_string($categoryHeader) ? $categoryHeader : 'X-Access-Justification-Category',
            minJustificationLength: is_int($minJustification) ? $minJustification : 10,
        );
    }
}
