<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\RiskLevel;
use Pulsar\Extension\Psd2\Domain\ScaExemption;
use Pulsar\Extension\Psd2\Domain\TransactionRiskAssessment;

final class TransactionRiskAssessmentTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();

        $assessment = new TransactionRiskAssessment(
            assessmentId: 'assess_001',
            transactionId: 'tx_001',
            riskScore: 0.45,
            riskLevel: RiskLevel::Medium,
            exemption: ScaExemption::None,
            scaRequired: true,
            matchedRules: ['velocity_count_exceeded'],
            assessedAt: $now,
        );

        self::assertSame('assess_001', $assessment->assessmentId);
        self::assertSame('tx_001', $assessment->transactionId);
        self::assertSame(0.45, $assessment->riskScore);
        self::assertSame(RiskLevel::Medium, $assessment->riskLevel);
        self::assertSame(ScaExemption::None, $assessment->exemption);
        self::assertTrue($assessment->scaRequired);
        self::assertSame(['velocity_count_exceeded'], $assessment->matchedRules);
    }

    #[Test]
    public function toArrayContainsAllFields(): void
    {
        $assessment = new TransactionRiskAssessment(
            assessmentId: 'assess_001',
            transactionId: 'tx_001',
            riskScore: 0.1,
            riskLevel: RiskLevel::Low,
            exemption: ScaExemption::LowValueTransaction,
            scaRequired: false,
            matchedRules: [],
            assessedAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );

        $array = $assessment->toArray();

        self::assertSame('assess_001', $array['assessment_id']);
        self::assertSame('tx_001', $array['transaction_id']);
        self::assertSame(0.1, $array['risk_score']);
        self::assertSame('low', $array['risk_level']);
        self::assertSame('low_value', $array['exemption']);
        self::assertFalse($array['sca_required']);
        self::assertSame([], $array['matched_rules']);
        self::assertArrayHasKey('assessed_at', $array);
    }
}
