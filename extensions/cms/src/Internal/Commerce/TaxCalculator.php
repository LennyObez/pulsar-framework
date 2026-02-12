<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\TaxCalculatorInterface;
use Pulsar\Extension\Cms\Commerce\TaxLineItem;
use Pulsar\Extension\Cms\Commerce\TaxResult;

use function in_array;
use function intval;
use function round;

/**
 * Tax calculator supporting per-item rates, multi-country rules, and EU VAT reverse charge.
 */
#[Internal(reason: 'Use TaxCalculatorInterface for public API')]
final readonly class TaxCalculator implements TaxCalculatorInterface
{
    public function __construct(
        private CommerceConfig $config,
    ) {}

    public function calculate(array $items, string $billingCountry, ?string $vatNumber = null): TaxResult
    {
        $taxLineItems = [];
        $totalTax = 0;
        $reverseCharge = false;

        // Check EU VAT reverse charge: valid VAT number + different EU country
        if ($vatNumber !== null && $this->isEuCountry($billingCountry)) {
            $sellerCountry = $this->getSellerCountry();

            if ($sellerCountry !== $billingCountry && $this->isEuCountry($sellerCountry)) {
                $reverseCharge = true;
            }
        }

        foreach ($items as $item) {
            $rate = $reverseCharge ? 0.0 : $this->findTaxRate($item['taxCategory'], $billingCountry);
            $taxableAmount = (float) ($item['amount'] * $item['quantity']);
            $taxAmount = intval(round($taxableAmount * $rate));

            $taxLineItems[] = new TaxLineItem(
                productId: $item['productId'],
                taxRate: $rate,
                taxAmount: $taxAmount,
            );

            $totalTax += $taxAmount;
        }

        return new TaxResult(
            items: $taxLineItems,
            totalTax: $totalTax,
            reverseCharge: $reverseCharge,
        );
    }

    private function findTaxRate(?string $taxCategory, string $billingCountry): float
    {
        if ($taxCategory === null) {
            return 0.0;
        }

        foreach ($this->config->taxRates as $rateConfig) {
            if ($rateConfig->category === $taxCategory && in_array($billingCountry, $rateConfig->countryCodes, true)) {
                return $rateConfig->rate;
            }
        }

        // Fallback: try matching category without country constraint
        foreach ($this->config->taxRates as $rateConfig) {
            if ($rateConfig->category === $taxCategory && $rateConfig->countryCodes === []) {
                return $rateConfig->rate;
            }
        }

        return 0.0;
    }

    private function isEuCountry(string $countryCode): bool
    {
        return in_array($countryCode, $this->config->euCountryCodes, true);
    }

    private function getSellerCountry(): string
    {
        return $this->config->sellerCountry;
    }
}
