<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Internal\Monitoring;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Psd2\Config\RiskConfig;
use Pulsar\Extension\Psd2\Contracts\TransactionRiskAnalyzerInterface;
use Pulsar\Extension\Psd2\Contracts\VelocityTrackerInterface;
use Pulsar\Extension\Psd2\Domain\RiskLevel;
use Pulsar\Extension\Psd2\Domain\ScaExemption;
use Pulsar\Extension\Psd2\Domain\TransactionRiskAssessment;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function bin2hex;
use function in_array;
use function random_bytes;

/**
 * Default transaction risk analyzer per PSD2 RTS Art. 18.
 *
 * Performs risk scoring based on:
 * - Transaction amount (low-value exemption check)
 * - Velocity checks (transaction count and total amount per window)
 * - Configurable risk thresholds
 *
 * Determines whether SCA is required or an exemption can be applied.
 */
#[Internal(reason: 'Use TransactionRiskAnalyzerInterface')]
final readonly class TransactionRiskAnalyzer implements TransactionRiskAnalyzerInterface
{
    public function __construct(
        private VelocityTrackerInterface $velocityTracker,
        private RiskConfig $config,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    #[Override]
    public function analyze(
        string $identityId,
        string $transactionId,
        int $amountMinorUnits,
        string $currency,
        string $payeeId,
        array $context = [],
    ): TransactionRiskAssessment {
        /** @var list<string> $matchedRules */
        $matchedRules = [];
        $riskScore = 0.0;

        // Rule 1: Low-value transaction exemption (RTS Art. 16)
        $isLowValue = $amountMinorUnits <= $this->config->lowValueThresholdMinorUnits;

        // Rule 2: Velocity checks
        $window = $this->velocityTracker->getWindow(
            $identityId,
            $this->config->velocityWindowSeconds,
            $currency,
        );

        if ($window->exceedsCountThreshold($this->config->velocityMaxCount)) {
            $matchedRules[] = 'velocity_count_exceeded';
            $riskScore += 0.3;
        }

        if ($window->exceedsAmountThreshold($this->config->velocityMaxAmountMinorUnits)) {
            $matchedRules[] = 'velocity_amount_exceeded';
            $riskScore += 0.3;
        }

        // Rule 3: High-value transaction
        if ($amountMinorUnits > $this->config->lowValueThresholdMinorUnits * 10) {
            $matchedRules[] = 'high_value_transaction';
            $riskScore += 0.2;
        }

        // Clamp score to [0, 1]
        $riskScore = min(1.0, max(0.0, $riskScore));

        // Determine risk level
        $riskLevel = match (true) {
            $riskScore >= $this->config->highThreshold => RiskLevel::High,
            $riskScore >= $this->config->lowThreshold => RiskLevel::Medium,
            default => RiskLevel::Low,
        };

        // Determine SCA exemption
        // High-value transactions always require SCA (PSD2 Article 97)
        $isHighValue = in_array('high_value_transaction', $matchedRules, true);
        $exemption = ScaExemption::None;
        $scaRequired = true;

        if (!$isHighValue && $riskLevel === RiskLevel::Low && $isLowValue) {
            $exemption = ScaExemption::LowValueTransaction;
            $scaRequired = false;
        } elseif (!$isHighValue && $riskLevel === RiskLevel::Low && $riskScore < $this->config->lowThreshold) {
            $exemption = ScaExemption::TransactionRiskAnalysis;
            $scaRequired = false;
        }

        // Record the transaction in velocity tracker
        $this->velocityTracker->record($identityId, $amountMinorUnits, $currency);

        $assessment = new TransactionRiskAssessment(
            assessmentId: bin2hex(random_bytes(16)),
            transactionId: $transactionId,
            riskScore: $riskScore,
            riskLevel: $riskLevel,
            exemption: $exemption,
            scaRequired: $scaRequired,
            matchedRules: $matchedRules,
            assessedAt: new DateTimeImmutable(),
        );

        $this->auditLogger?->log(
            AuditEvent::SecurityEvent,
            $scaRequired ? AuditOutcome::Denied : AuditOutcome::Success,
            $identityId,
            'psd2_transaction_risk_assessed',
            metadata: [
                'assessment_id' => $assessment->assessmentId,
                'transaction_id' => $transactionId,
                'risk_level' => $riskLevel->value,
                'risk_score' => (string) $riskScore,
                'sca_required' => $scaRequired ? 'true' : 'false',
                'exemption' => $exemption->value,
            ],
        );

        return $assessment;
    }
}
