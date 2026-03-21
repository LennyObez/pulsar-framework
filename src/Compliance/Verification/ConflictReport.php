<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;

/**
 * Describes a conflict between two compliance frameworks and its resolution.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ConflictReport
{
    /**
     * @param list<string> $resolutionSteps Ordered steps to resolve the conflict
     */
    public function __construct(
        public ComplianceFramework $frameworkA,
        public ComplianceFramework $frameworkB,
        public string $requirementA,
        public string $requirementB,
        public string $description,
        public string $resolution,
        public array $resolutionSteps = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'framework_a' => $this->frameworkA->value,
            'framework_b' => $this->frameworkB->value,
            'requirement_a' => $this->requirementA,
            'requirement_b' => $this->requirementB,
            'description' => $this->description,
            'resolution' => $this->resolution,
            'resolution_steps' => $this->resolutionSteps,
        ];
    }
}
