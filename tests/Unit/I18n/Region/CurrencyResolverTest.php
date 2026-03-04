<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\I18n\Region;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\I18n\Region\Continent;
use Pulsar\I18n\Region\Country;
use Pulsar\I18n\Region\CountryRegistry;
use Pulsar\I18n\Region\CurrencyResolver;

use function count;

#[CoversClass(CurrencyResolver::class)]
final class CurrencyResolverTest extends TestCase
{
    private CurrencyResolver $resolver;
    private CountryRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CountryRegistry();
        $this->resolver = new CurrencyResolver($this->registry);
    }

    #[Test]
    #[DataProvider('countryCurrencyProvider')]
    public function currencyForCountryReturnsCorrectCurrency(string $countryCode, string $expectedCurrency): void
    {
        self::assertSame($expectedCurrency, $this->resolver->currencyForCountry($countryCode));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function countryCurrencyProvider(): iterable
    {
        yield 'Belgium uses EUR' => ['BE', 'EUR'];
        yield 'Netherlands uses EUR' => ['NL', 'EUR'];
        yield 'United States uses USD' => ['US', 'USD'];
        yield 'United Kingdom uses GBP' => ['GB', 'GBP'];
        yield 'Japan uses JPY' => ['JP', 'JPY'];
        yield 'Switzerland uses CHF' => ['CH', 'CHF'];
        yield 'Sweden uses SEK' => ['SE', 'SEK'];
        yield 'Poland uses PLN' => ['PL', 'PLN'];
    }

    #[Test]
    public function currencyForCountryReturnsUsdForUnknownCode(): void
    {
        self::assertSame('USD', $this->resolver->currencyForCountry('XX'));
    }

    #[Test]
    public function currencyForCountryHandlesEmptyString(): void
    {
        self::assertSame('USD', $this->resolver->currencyForCountry(''));
    }

    #[Test]
    public function currencyForReturnsCountryCurrency(): void
    {
        $country = new Country(
            code: 'BE',
            name: 'Belgium',
            continent: Continent::Europe,
            languages: ['nl'],
            currency: 'EUR',
            flag: 'BE',
        );

        self::assertSame('EUR', $this->resolver->currencyFor($country));
    }

    #[Test]
    public function belgiumGetsBancontactAndPayconiq(): void
    {
        $methods = $this->resolver->paymentMethodsForCountry('BE');

        self::assertContains('card', $methods);
        self::assertContains('paypal', $methods);
        self::assertContains('bancontact', $methods);
        self::assertContains('payconiq', $methods);
        self::assertContains('sepa', $methods);
    }

    #[Test]
    public function netherlandsGetsIdeal(): void
    {
        $methods = $this->resolver->paymentMethodsForCountry('NL');

        self::assertContains('card', $methods);
        self::assertContains('paypal', $methods);
        self::assertContains('ideal', $methods);
        self::assertContains('sepa', $methods);
    }

    #[Test]
    public function germanyGetsKlarna(): void
    {
        $methods = $this->resolver->paymentMethodsForCountry('DE');

        self::assertContains('card', $methods);
        self::assertContains('sepa', $methods);
        self::assertContains('klarna_pay_later', $methods);
        self::assertContains('klarna_pay_now', $methods);
    }

    #[Test]
    #[DataProvider('euCountryProvider')]
    public function euCountriesGetSepa(string $code): void
    {
        $methods = $this->resolver->paymentMethodsForCountry($code);

        self::assertContains('sepa', $methods, "EU country {$code} should have SEPA");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function euCountryProvider(): iterable
    {
        yield 'France' => ['FR'];
        yield 'Italy' => ['IT'];
        yield 'Spain' => ['ES'];
        yield 'Portugal' => ['PT'];
        yield 'Austria' => ['AT'];
        yield 'Finland' => ['FI'];
        yield 'Ireland' => ['IE'];
    }

    #[Test]
    public function nonEuCountriesDoNotGetSepa(): void
    {
        $methods = $this->resolver->paymentMethodsForCountry('US');

        self::assertNotContains('sepa', $methods);
    }

    #[Test]
    public function allCountriesAlwaysGetCardAndPaypal(): void
    {
        $codes = ['US', 'GB', 'JP', 'BR', 'AU', 'IN', 'ZA'];

        foreach ($codes as $code) {
            $methods = $this->resolver->paymentMethodsForCountry($code);
            self::assertContains('card', $methods, "Country {$code} should always have card");
            self::assertContains('paypal', $methods, "Country {$code} should always have PayPal");
        }
    }

    #[Test]
    public function nordicCountriesGetKlarnaPayLater(): void
    {
        $nordics = ['SE', 'NO', 'FI', 'DK'];

        foreach ($nordics as $code) {
            $methods = $this->resolver->paymentMethodsForCountry($code);
            self::assertContains(
                'klarna_pay_later',
                $methods,
                "Nordic country {$code} should have Klarna Pay Later",
            );
        }
    }

    #[Test]
    public function isEuCountryReturnsCorrectValues(): void
    {
        self::assertTrue($this->resolver->isEuCountry('FR'));
        self::assertTrue($this->resolver->isEuCountry('DE'));
        self::assertTrue($this->resolver->isEuCountry('BE'));
        self::assertFalse($this->resolver->isEuCountry('US'));
        self::assertFalse($this->resolver->isEuCountry('GB'));
        self::assertFalse($this->resolver->isEuCountry('CH'));
    }

    #[Test]
    public function isEuCountryIsCaseInsensitive(): void
    {
        self::assertTrue($this->resolver->isEuCountry('fr'));
        self::assertTrue($this->resolver->isEuCountry('Fr'));
    }

    #[Test]
    public function euCountryCodesReturnsTwentySevenMembers(): void
    {
        $codes = $this->resolver->euCountryCodes();

        self::assertCount(27, $codes);
    }

    #[Test]
    public function countryPaymentOverridesTakePrecedence(): void
    {
        $overrides = [
            'BE' => ['card', 'bancontact'],
            'NL' => ['card', 'ideal', 'paypal'],
        ];

        $resolver = new CurrencyResolver($this->registry, $overrides);

        $beMethods = $resolver->paymentMethodsForCountry('BE');
        self::assertSame(['card', 'bancontact'], $beMethods);

        $nlMethods = $resolver->paymentMethodsForCountry('NL');
        self::assertSame(['card', 'ideal', 'paypal'], $nlMethods);

        // Non-overridden countries use default logic
        $frMethods = $resolver->paymentMethodsForCountry('FR');
        self::assertContains('sepa', $frMethods);
    }

    #[Test]
    public function paymentMethodsHaveNoDuplicates(): void
    {
        $methods = $this->resolver->paymentMethodsForCountry('BE');

        self::assertSame(
            count($methods),
            count(array_unique($methods)),
            'Payment methods should have no duplicates',
        );
    }
}
