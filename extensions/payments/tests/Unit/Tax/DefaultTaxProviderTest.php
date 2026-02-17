<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Tax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Domain\Money;
use Pulsar\Extension\Payments\Tax\DefaultTaxProvider;
use Pulsar\Extension\Payments\Tax\TaxCalculationRequest;

final class DefaultTaxProviderTest extends TestCase
{
    private DefaultTaxProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new DefaultTaxProvider();
    }

    #[Test]
    public function calculateTaxForGermanyReturns19Percent(): void
    {
        $amount = Money::of(10000, Currency::EUR); // 100.00 EUR

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'DE',
        ));

        self::assertSame(1900, $result->rateBasisPoints);
        self::assertSame(1900, $result->taxAmount->amount); // 19.00 EUR
        self::assertSame('DE', $result->jurisdiction);
        self::assertFalse($result->isExempt);
        self::assertCount(1, $result->lineItems);
        self::assertSame('VAT', $result->lineItems[0]->name);
    }

    #[Test]
    public function calculateTaxForUkReturns20Percent(): void
    {
        $amount = Money::of(5000, Currency::GBP);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'GB',
        ));

        self::assertSame(2000, $result->rateBasisPoints);
        self::assertSame(1000, $result->taxAmount->amount); // 10.00 GBP
    }

    #[Test]
    public function calculateTaxForAustraliaReturnsGst(): void
    {
        $amount = Money::of(10000, Currency::AUD);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'AU',
        ));

        self::assertSame(1000, $result->rateBasisPoints);
        self::assertSame('GST', $result->lineItems[0]->name);
    }

    #[Test]
    public function calculateTaxForUsStateAppliesStateTax(): void
    {
        $amount = Money::of(10000, Currency::USD);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'US',
            regionCode: 'CA',
        ));

        self::assertSame(725, $result->rateBasisPoints);
        self::assertSame('US-CA', $result->jurisdiction);
        self::assertSame('State Sales Tax', $result->lineItems[0]->name);
    }

    #[Test]
    public function calculateTaxForUsNoTaxState(): void
    {
        $amount = Money::of(10000, Currency::USD);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'US',
            regionCode: 'OR',
        ));

        self::assertSame(0, $result->rateBasisPoints);
        self::assertTrue($result->taxAmount->isZero());
        self::assertSame([], $result->lineItems);
    }

    #[Test]
    public function calculateTaxForUnknownCountryReturnsZero(): void
    {
        $amount = Money::of(10000, Currency::USD);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'XX',
        ));

        self::assertSame(0, $result->rateBasisPoints);
        self::assertTrue($result->taxAmount->isZero());
    }

    #[Test]
    #[DataProvider('euVatProvider')]
    public function calculateTaxForEuCountries(string $country, int $expectedBp): void
    {
        $amount = Money::of(10000, Currency::EUR);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: $country,
        ));

        self::assertSame($expectedBp, $result->rateBasisPoints);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function euVatProvider(): iterable
    {
        yield 'France' => ['FR', 2000];
        yield 'Netherlands' => ['NL', 2100];
        yield 'Hungary' => ['HU', 2700];
        yield 'Luxembourg' => ['LU', 1700];
        yield 'Denmark' => ['DK', 2500];
    }

    #[Test]
    public function validateExemptionAcceptsValidEuVatId(): void
    {
        self::assertTrue($this->provider->validateExemption('BE0123456789', 'BE'));
        self::assertTrue($this->provider->validateExemption('DE123456789', 'DE'));
    }

    #[Test]
    public function validateExemptionRejectsEmptyTaxId(): void
    {
        self::assertFalse($this->provider->validateExemption('', 'DE'));
    }

    #[Test]
    public function validateExemptionRejectsInvalidFormat(): void
    {
        self::assertFalse($this->provider->validateExemption('invalid', 'DE'));
    }

    #[Test]
    public function caseInsensitiveCountryCode(): void
    {
        $amount = Money::of(10000, Currency::EUR);

        $result = $this->provider->calculateTax(new TaxCalculationRequest(
            amount: $amount,
            countryCode: 'de',
        ));

        self::assertSame(1900, $result->rateBasisPoints);
    }
}
