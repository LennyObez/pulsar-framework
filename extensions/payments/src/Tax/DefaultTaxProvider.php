<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tax;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Payments\Domain\RoundingMode;

use function in_array;
use function strlen;

/**
 * Default tax provider using configurable rate tables.
 *
 * Provides a built-in tax calculation based on country/region rate
 * tables. For production use with complex tax requirements, replace
 * with an integration provider (Avalara, TaxJar, etc.).
 */
#[Internal(reason: 'Default tax provider; use TaxProviderInterface')]
final readonly class DefaultTaxProvider implements TaxProviderInterface
{
    /**
     * Standard VAT/sales tax rates by country (ISO 3166-1 alpha-2).
     * Values in basis points (10000 = 100%).
     *
     * @var array<string, int>
     */
    private const array COUNTRY_RATES = [
        'AT' => 2000, // Austria 20%
        'BE' => 2100, // Belgium 21%
        'BG' => 2000, // Bulgaria 20%
        'CY' => 1900, // Cyprus 19%
        'CZ' => 2100, // Czech Republic 21%
        'DE' => 1900, // Germany 19%
        'DK' => 2500, // Denmark 25%
        'EE' => 2200, // Estonia 22%
        'ES' => 2100, // Spain 21%
        'FI' => 2550, // Finland 25.5%
        'FR' => 2000, // France 20%
        'GR' => 2400, // Greece 24%
        'HR' => 2500, // Croatia 25%
        'HU' => 2700, // Hungary 27%
        'IE' => 2300, // Ireland 23%
        'IT' => 2200, // Italy 22%
        'LT' => 2100, // Lithuania 21%
        'LU' => 1700, // Luxembourg 17%
        'LV' => 2100, // Latvia 21%
        'MT' => 1800, // Malta 18%
        'NL' => 2100, // Netherlands 21%
        'PL' => 2300, // Poland 23%
        'PT' => 2300, // Portugal 23%
        'RO' => 1900, // Romania 19%
        'SE' => 2500, // Sweden 25%
        'SI' => 2200, // Slovenia 22%
        'SK' => 2000, // Slovakia 20%
        'GB' => 2000, // UK 20%
        'NO' => 2500, // Norway 25%
        'CH' => 810,  // Switzerland 8.1%
        'AU' => 1000, // Australia 10% GST
        'NZ' => 1500, // New Zealand 15% GST
        'CA' => 500,  // Canada 5% GST (federal only)
        'JP' => 1000, // Japan 10%
        'KR' => 1000, // South Korea 10%
        'SG' => 900,  // Singapore 9%
        'IN' => 1800, // India 18% GST
        'BR' => 1700, // Brazil ~17%
    ];

    /**
     * US state sales tax rates in basis points.
     *
     * @var array<string, int>
     */
    private const array US_STATE_RATES = [
        'AL' => 400, 'AZ' => 560, 'AR' => 650, 'CA' => 725, 'CO' => 290,
        'CT' => 635, 'FL' => 600, 'GA' => 400, 'HI' => 400, 'ID' => 600,
        'IL' => 625, 'IN' => 700, 'IA' => 600, 'KS' => 650, 'KY' => 600,
        'LA' => 445, 'ME' => 550, 'MD' => 600, 'MA' => 625, 'MI' => 600,
        'MN' => 688, 'MS' => 700, 'MO' => 423, 'NE' => 550, 'NV' => 685,
        'NJ' => 663, 'NM' => 513, 'NY' => 400, 'NC' => 475, 'ND' => 500,
        'OH' => 575, 'OK' => 450, 'PA' => 600, 'RI' => 700, 'SC' => 600,
        'SD' => 450, 'TN' => 700, 'TX' => 625, 'UT' => 610, 'VT' => 600,
        'VA' => 530, 'WA' => 650, 'WV' => 600, 'WI' => 500, 'WY' => 400,
        'DC' => 600,
        // States with no sales tax:
        'AK' => 0, 'DE' => 0, 'MT' => 0, 'NH' => 0, 'OR' => 0,
    ];

    #[Override]
    public function calculateTax(TaxCalculationRequest $request): TaxCalculationResult
    {
        $country = strtoupper($request->countryCode);

        if ($country === 'US') {
            return $this->calculateUsTax($request);
        }

        $rateBp = self::COUNTRY_RATES[$country] ?? 0;
        $taxAmount = $request->amount->percentage($rateBp, RoundingMode::HalfUp);

        $jurisdiction = $country;
        $lineItems = [];

        if ($rateBp > 0) {
            $taxName = in_array($country, ['AU', 'NZ', 'SG', 'IN', 'CA'], true)
                ? 'GST'
                : 'VAT';
            $lineItems[] = new TaxLineItem(
                name: $taxName,
                rateBasisPoints: $rateBp,
                amount: $taxAmount,
                jurisdiction: $jurisdiction,
            );
        }

        return new TaxCalculationResult(
            taxAmount: $taxAmount,
            rateBasisPoints: $rateBp,
            jurisdiction: $jurisdiction,
            lineItems: $lineItems,
        );
    }

    #[Override]
    public function validateExemption(string $taxId, string $countryCode): bool
    {
        // Basic format validation: a production provider would verify with tax authority
        $taxId = trim($taxId);

        if ($taxId === '') {
            return false;
        }

        // EU VAT ID format: 2 letter country code + 2-13 alphanumeric chars
        if (strlen($countryCode) === 2 && preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $taxId)) {
            return true;
        }

        return false;
    }

    private function calculateUsTax(TaxCalculationRequest $request): TaxCalculationResult
    {
        $state = strtoupper($request->regionCode);
        $rateBp = self::US_STATE_RATES[$state] ?? 0;
        $taxAmount = $request->amount->percentage($rateBp, RoundingMode::HalfUp);

        $lineItems = [];

        if ($rateBp > 0) {
            $lineItems[] = new TaxLineItem(
                name: 'State Sales Tax',
                rateBasisPoints: $rateBp,
                amount: $taxAmount,
                jurisdiction: "US-{$state}",
            );
        }

        return new TaxCalculationResult(
            taxAmount: $taxAmount,
            rateBasisPoints: $rateBp,
            jurisdiction: "US-{$state}",
            lineItems: $lineItems,
        );
    }
}
