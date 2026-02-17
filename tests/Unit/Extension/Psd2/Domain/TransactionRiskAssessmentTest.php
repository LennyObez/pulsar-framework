<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Psd2\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\RiskLevel;
use Pulsar\Extension\Psd2\Domain\ScaExemption;
use Pulsar\Extension\Psd2\Domain\TransactionRiskAssessment;

#[CoversClass(TransactionRiskAssessment::class)]
final class TransactionRiskAssessmentTest extends TestCase
{
    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $assessedAt = new DateTimeImmutable('2025-03-15 14:30:00');
        $assessment = new TransactionRiskAssessment(
            assessmentId: 'ra-001',
            transactionId: 'tx-001',
            riskScore: 0.15,
            riskLevel: RiskLevel::Low,
            exemption: ScaExemption::TransactionRiskAnalysis,
            scaRequired: false,
            matchedRules: ['rule_geo_match', 'rule_device_known'],
            assessedAt: $assessedAt,
        );

        $array = $assessment->toArray();

        self::assertSame('ra-001', $array['assessment_id']);
        self::assertSame('tx-001', $array['transaction_id']);
        self::assertSame(0.15, $array['risk_score']);
        self::assertSame('low', $array['risk_level']);
        self::assertSame('transaction_risk_analysis', $array['exemption']);
        self::assertFalse($array['sca_required']);
        self::assertSame(['rule_geo_match', 'rule_device_known'], $array['matched_rules']);
        self::assertIsString($array['assessed_at']);
        self::assertStringContainsString('2025-03-15', $array['assessed_at']);
    }

    #[Test]
    public function highRiskAssessmentRequiresSca(): void
    {
        $assessment = new TransactionRiskAssessment(
            assessmentId: 'ra-002',
            transactionId: 'tx-002',
            riskScore: 0.92,
            riskLevel: RiskLevel::High,
            exemption: ScaExemption::None,
            scaRequired: true,
            matchedRules: ['rule_new_device', 'rule_high_amount', 'rule_geo_anomaly'],
            assessedAt: new DateTimeImmutable(),
        );

        self::assertTrue($assessment->scaRequired);
        self::assertSame(RiskLevel::High, $assessment->riskLevel);
        self::assertCount(3, $assessment->matchedRules);
    }

    #[Test]
    public function lowValueExemptionDoesNotRequireSca(): void
    {
        $assessment = new TransactionRiskAssessment(
            assessmentId: 'ra-003',
            transactionId: 'tx-003',
            riskScore: 0.05,
            riskLevel: RiskLevel::Low,
            exemption: ScaExemption::LowValueTransaction,
            scaRequired: false,
            matchedRules: [],
            assessedAt: new DateTimeImmutable(),
        );

        self::assertFalse($assessment->scaRequired);
        self::assertSame(ScaExemption::LowValueTransaction, $assessment->exemption);
    }
}
