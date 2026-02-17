<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Domain\PaymentMethod;
use Pulsar\Extension\Payments\Internal\Security\Psd2StrongAuth;

final class Psd2StrongAuthTest extends TestCase
{
    #[Test]
    public function requires_sca_for_high_value_card_payment(): void
    {
        $amount = Money::of(amount: 5000, currency: Currency::EUR);

        self::assertTrue(Psd2StrongAuth::requiresSca($amount, PaymentMethod::Card));
    }

    #[Test]
    public function low_value_eur_card_payment_exempt(): void
    {
        $amount = Money::of(amount: 2500, currency: Currency::EUR);

        self::assertFalse(Psd2StrongAuth::requiresSca($amount, PaymentMethod::Card));
    }

    #[Test]
    public function recurring_payment_exempt(): void
    {
        $amount = Money::of(amount: 10000, currency: Currency::EUR);

        self::assertFalse(Psd2StrongAuth::requiresSca(
            $amount,
            PaymentMethod::Card,
            isRecurring: true,
        ));
    }

    #[Test]
    public function trusted_beneficiary_exempt(): void
    {
        $amount = Money::of(amount: 10000, currency: Currency::EUR);

        self::assertFalse(Psd2StrongAuth::requiresSca(
            $amount,
            PaymentMethod::Card,
            isTrustedBeneficiary: true,
        ));
    }

    #[Test]
    public function paypal_does_not_require_sca(): void
    {
        $amount = Money::of(amount: 50000, currency: Currency::EUR);

        self::assertFalse(Psd2StrongAuth::requiresSca($amount, PaymentMethod::PayPal));
    }

    #[Test]
    public function non_eur_low_value_still_requires_sca(): void
    {
        $amount = Money::of(amount: 2500, currency: Currency::USD);

        self::assertTrue(Psd2StrongAuth::requiresSca($amount, PaymentMethod::Card));
    }

    #[Test]
    public function exemption_reason_returns_low_value(): void
    {
        $amount = Money::of(amount: 1000, currency: Currency::EUR);

        self::assertSame('low_value', Psd2StrongAuth::exemptionReason($amount, PaymentMethod::Card));
    }

    #[Test]
    public function exemption_reason_returns_recurring_mit(): void
    {
        $amount = Money::of(amount: 5000, currency: Currency::EUR);

        self::assertSame('recurring_mit', Psd2StrongAuth::exemptionReason(
            $amount,
            PaymentMethod::Card,
            isRecurring: true,
        ));
    }

    #[Test]
    public function exemption_reason_returns_trusted_beneficiary(): void
    {
        $amount = Money::of(amount: 5000, currency: Currency::EUR);

        self::assertSame('trusted_beneficiary', Psd2StrongAuth::exemptionReason(
            $amount,
            PaymentMethod::Card,
            isTrustedBeneficiary: true,
        ));
    }

    #[Test]
    public function exemption_reason_returns_method_not_applicable(): void
    {
        $amount = Money::of(amount: 5000, currency: Currency::EUR);

        self::assertSame('method_not_applicable', Psd2StrongAuth::exemptionReason(
            $amount,
            PaymentMethod::PayPal,
        ));
    }

    #[Test]
    public function exemption_reason_returns_null_when_sca_required(): void
    {
        $amount = Money::of(amount: 5000, currency: Currency::EUR);

        self::assertNull(Psd2StrongAuth::exemptionReason($amount, PaymentMethod::Card));
    }
}
