<?php

declare(strict_types=1);

namespace Pulsar\I18n\Region;

use NoDiscard;
use Pulsar\Api\Api;

use function array_unique;
use function array_values;
use function in_array;

/**
 * Resolves the default currency and available payment methods for a country.
 *
 * Maps countries to their primary currency and determines which
 * payment methods should be prominently displayed based on the
 * visitor's region. Integrates with the Payments extension's
 * per-country payment method configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CurrencyResolver
{
    /** @var list<string> ISO 3166-1 alpha-2 codes for EU member states */
    private const array EU_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
        'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
        'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    /**
     * @param array<string, list<string>> $countryPaymentOverrides Per-country payment method overrides
     */
    public function __construct(
        private CountryRegistry $registry,
        private array $countryPaymentOverrides = [],
    ) {}

    /**
     * Resolve the default currency for a country code.
     *
     * @return non-empty-string ISO 4217 currency code
     */
    #[NoDiscard]
    public function currencyForCountry(string $countryCode): string
    {
        if ($countryCode === '') {
            return 'USD';
        }

        $country = $this->registry->get($countryCode);

        if ($country === null) {
            return 'USD';
        }

        return $country->currency;
    }

    /**
     * Resolve the default currency for a Country instance.
     *
     * @return non-empty-string ISO 4217 currency code
     */
    #[NoDiscard]
    public function currencyFor(Country $country): string
    {
        return $country->currency;
    }

    /**
     * Get the list of payment methods available for a country.
     *
     * Priority logic:
     * - Belgium: Bancontact prominent
     * - Netherlands: iDEAL prominent
     * - Any EU country: SEPA available
     * - Always: card (Stripe) and PayPal
     *
     * @return list<string> Payment method identifiers
     */
    #[NoDiscard]
    public function paymentMethodsForCountry(string $countryCode): array
    {
        // Check for explicit per-country overrides first
        $code = strtoupper($countryCode);

        if (isset($this->countryPaymentOverrides[$code])) {
            return $this->countryPaymentOverrides[$code];
        }

        $methods = ['card', 'paypal'];

        // EU-specific methods
        if ($this->isEuCountry($code)) {
            $methods[] = 'sepa';
        }

        // Country-specific prominent methods
        if ($code === 'BE') {
            $methods[] = 'bancontact';
            $methods[] = 'payconiq';
        }

        if ($code === 'NL') {
            $methods[] = 'ideal';
        }

        if ($code === 'DE' || $code === 'AT' || $code === 'CH') {
            $methods[] = 'klarna_pay_later';
            $methods[] = 'klarna_pay_now';
        }

        if ($code === 'SE' || $code === 'NO' || $code === 'FI' || $code === 'DK') {
            $methods[] = 'klarna_pay_later';
        }

        return array_values(array_unique($methods));
    }

    /**
     * Whether a country is an EU member state.
     */
    #[NoDiscard]
    public function isEuCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), self::EU_COUNTRY_CODES, true);
    }

    /**
     * Get the list of EU country codes.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function euCountryCodes(): array
    {
        return self::EU_COUNTRY_CODES;
    }
}
