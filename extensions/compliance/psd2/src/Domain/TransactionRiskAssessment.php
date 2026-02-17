<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a transaction risk analysis per PSD2 RTS Art. 18.
 *
 * Contains the risk score, level, applicable exemption, and
 * the individual rule matches that contributed to the assessment.
 */
#[Api(since: '1.0.0')]
final readonly class TransactionRiskAssessment
{
    /**
     * @param list<string> $matchedRules Rule identifiers that fired during analysis
     */
    public function __construct(
        public string $assessmentId,
        public string $transactionId,
        public float $riskScore,
        public RiskLevel $riskLevel,
        public ScaExemption $exemption,
        public bool $scaRequired,
        public array $matchedRules,
        public DateTimeImmutable $assessedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'assessment_id' => $this->assessmentId,
            'transaction_id' => $this->transactionId,
            'risk_score' => $this->riskScore,
            'risk_level' => $this->riskLevel->value,
            'exemption' => $this->exemption->value,
            'sca_required' => $this->scaRequired,
            'matched_rules' => $this->matchedRules,
            'assessed_at' => $this->assessedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}
