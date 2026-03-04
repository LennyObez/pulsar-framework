<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Domain\ScaExemption;

final class ScaExemptionTest extends TestCase
{
    #[Test]
    public function allCasesHaveStringValues(): void
    {
        self::assertSame('low_value', ScaExemption::LowValueTransaction->value);
        self::assertSame('trusted_beneficiary', ScaExemption::TrustedBeneficiary->value);
        self::assertSame('recurring_payment', ScaExemption::RecurringPayment->value);
        self::assertSame('transaction_risk_analysis', ScaExemption::TransactionRiskAnalysis->value);
        self::assertSame('contactless', ScaExemption::ContactlessPayment->value);
        self::assertSame('corporate_payment', ScaExemption::CorporatePayment->value);
        self::assertSame('none', ScaExemption::None->value);
    }
}
