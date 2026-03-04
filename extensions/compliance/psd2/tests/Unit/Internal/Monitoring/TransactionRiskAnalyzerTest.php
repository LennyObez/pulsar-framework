<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Internal\Monitoring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\RiskConfig;
use Pulsar\Extension\Psd2\Domain\RiskLevel;
use Pulsar\Extension\Psd2\Domain\ScaExemption;
use Pulsar\Extension\Psd2\Internal\Monitoring\InMemoryVelocityTracker;
use Pulsar\Extension\Psd2\Internal\Monitoring\TransactionRiskAnalyzer;

final class TransactionRiskAnalyzerTest extends TestCase
{
    private InMemoryVelocityTracker $tracker;
    private TransactionRiskAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->tracker = new InMemoryVelocityTracker();
        $this->analyzer = new TransactionRiskAnalyzer(
            $this->tracker,
            new RiskConfig(
                lowThreshold: 0.3,
                highThreshold: 0.7,
                velocityWindowSeconds: 3600,
                velocityMaxCount: 5,
                velocityMaxAmountMinorUnits: 50000,
                lowValueThresholdMinorUnits: 3000,
            ),
        );
    }

    #[Test]
    public function analyzeLowValueTransactionGrantsExemption(): void
    {
        $assessment = $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_001',
            amountMinorUnits: 1000, // below 3000 threshold
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertSame(RiskLevel::Low, $assessment->riskLevel);
        self::assertSame(ScaExemption::LowValueTransaction, $assessment->exemption);
        self::assertFalse($assessment->scaRequired);
    }

    #[Test]
    public function analyzeHighValueTransactionRequiresSca(): void
    {
        $assessment = $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_001',
            amountMinorUnits: 50000, // high value (> threshold * 10)
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertTrue($assessment->scaRequired);
        self::assertContains('high_value_transaction', $assessment->matchedRules);
    }

    #[Test]
    public function analyzeVelocityCountExceededRaisesRisk(): void
    {
        // Pre-fill velocity tracker to exceed count threshold
        for ($i = 0; $i < 5; $i++) {
            $this->tracker->record('user_001', 1000, 'EUR');
        }

        $assessment = $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_006',
            amountMinorUnits: 1000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertContains('velocity_count_exceeded', $assessment->matchedRules);
        self::assertTrue($assessment->riskScore >= 0.3);
    }

    #[Test]
    public function analyzeVelocityAmountExceededRaisesRisk(): void
    {
        // Pre-fill velocity tracker to exceed amount threshold
        $this->tracker->record('user_001', 50000, 'EUR');

        $assessment = $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_002',
            amountMinorUnits: 1000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertContains('velocity_amount_exceeded', $assessment->matchedRules);
    }

    #[Test]
    public function analyzeMultipleRulesFireProducesHighRisk(): void
    {
        // Exceed both velocity thresholds
        for ($i = 0; $i < 5; $i++) {
            $this->tracker->record('user_001', 10000, 'EUR');
        }

        $assessment = $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_006',
            amountMinorUnits: 40000, // also high value
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertSame(RiskLevel::High, $assessment->riskLevel);
        self::assertTrue($assessment->scaRequired);
        self::assertSame(ScaExemption::None, $assessment->exemption);
    }

    #[Test]
    public function analyzeRecordsTransactionInVelocityTracker(): void
    {
        $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_001',
            amountMinorUnits: 2000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        $window = $this->tracker->getWindow('user_001', 3600, 'EUR');
        self::assertSame(1, $window->transactionCount);
        self::assertSame(2000, $window->totalAmountMinorUnits);
    }

    #[Test]
    public function analyzeReturnsUniqueAssessmentIds(): void
    {
        $a1 = $this->analyzer->analyze('user_001', 'tx_001', 1000, 'EUR', 'p1');
        $a2 = $this->analyzer->analyze('user_001', 'tx_002', 1000, 'EUR', 'p1');

        self::assertNotSame($a1->assessmentId, $a2->assessmentId);
    }

    #[Test]
    public function analyzeClampRiskScoreToMax1(): void
    {
        // Create enough conditions to push score past 1.0
        for ($i = 0; $i < 10; $i++) {
            $this->tracker->record('user_001', 20000, 'EUR');
        }

        $assessment = $this->analyzer->analyze(
            identityId: 'user_001',
            transactionId: 'tx_011',
            amountMinorUnits: 100000,
            currency: 'EUR',
            payeeId: 'payee_001',
        );

        self::assertLessThanOrEqual(1.0, $assessment->riskScore);
    }
}
