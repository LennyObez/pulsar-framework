<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Testing;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Resilience test result record per DORA Articles 24-27.
 *
 * Documents digital operational resilience testing activities including
 * vulnerability assessments, penetration tests, and threat-led penetration
 * testing (TLPT) for critical ICT systems.
 */
#[Api(since: '1.0.0')]
final readonly class ResilienceTestRecord
{
    /**
     * @param list<string> $findings     Identified vulnerabilities or weaknesses
     * @param list<string> $targetSystems  Systems targeted in the test
     */
    public function __construct(
        public string $id,
        public ResilienceTestType $testType,
        public DateTimeImmutable $executedAt,
        public string $executedBy,
        public string $result,
        public array $targetSystems = [],
        public array $findings = [],
        public ?string $remediationPlan = null,
        public ?DateTimeImmutable $remediationDeadline = null,
        public ?string $recoveryTimeActual = null,
        public bool $passed = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'test_type' => $this->testType->value,
            'executed_at' => $this->executedAt->format('Y-m-d'),
            'executed_by' => $this->executedBy,
            'result' => $this->result,
            'passed' => $this->passed,
        ];

        if ($this->targetSystems !== []) {
            $data['target_systems'] = $this->targetSystems;
        }

        if ($this->findings !== []) {
            $data['findings'] = $this->findings;
        }

        if ($this->remediationPlan !== null) {
            $data['remediation_plan'] = $this->remediationPlan;
        }

        if ($this->remediationDeadline !== null) {
            $data['remediation_deadline'] = $this->remediationDeadline->format('Y-m-d');
        }

        if ($this->recoveryTimeActual !== null) {
            $data['recovery_time_actual'] = $this->recoveryTimeActual;
        }

        return $data;
    }
}
