<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Commerce;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Commerce\CommerceConfig;
use Pulsar\Extension\Cms\Commerce\ShippingCalculatorInterface;
use Pulsar\Extension\Cms\Commerce\ShippingMethod;
use Pulsar\Extension\Cms\Commerce\ShippingRateConfig;
use Pulsar\Extension\Cms\Commerce\ShippingResult;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_string;

/**
 * Config-driven shipping calculator. Reads rates from CommerceConfig.
 * Digital-only orders receive free shipping with the Digital method.
 *
 * @psalm-api Bound to ShippingCalculatorInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Use ShippingCalculatorInterface for public API')]
final readonly class ConfigurableShippingCalculator implements ShippingCalculatorInterface
{
    public function __construct(
        private CommerceConfig $config,
    ) {}

    public function calculate(array $items, array $address): ShippingResult
    {
        // Digital-only orders: free shipping
        if ($this->allDigital($items)) {
            return new ShippingResult(
                amount: 0,
                method: ShippingMethod::Digital,
                estimatedDays: 0,
            );
        }

        /** @var mixed $rawCountry */
        $rawCountry = $address['country'] ?? null;
        $country = is_string($rawCountry) ? $rawCountry : '';
        $subtotal = $this->calculateSubtotal($items);

        // Find the best matching rate for the destination country
        $rate = $this->findRate($country);

        if ($rate === null) {
            // No configured rate: zero-cost standard shipping fallback
            return new ShippingResult(
                amount: 0,
                method: ShippingMethod::Standard,
                estimatedDays: null,
            );
        }

        // Free threshold check
        if ($rate->freeThreshold !== null && $subtotal >= $rate->freeThreshold) {
            return new ShippingResult(
                amount: 0,
                method: $rate->method,
                estimatedDays: $rate->estimatedDays,
            );
        }

        $physicalQuantity = $this->physicalItemCount($items);
        $amount = $rate->baseAmount + ($rate->perItemAmount * $physicalQuantity);

        return new ShippingResult(
            amount: $amount,
            method: $rate->method,
            estimatedDays: $rate->estimatedDays,
        );
    }

    public function availableMethods(array $address): array
    {
        /** @var mixed $rawCountry */
        $rawCountry = $address['country'] ?? null;
        $country = is_string($rawCountry) ? $rawCountry : '';

        $methods = array_filter(
            $this->config->shippingRates,
            static fn(ShippingRateConfig $rate): bool => $rate->countryCodes === []
                || in_array($country, $rate->countryCodes, true),
        );

        $result = array_values(array_map(
            static fn(ShippingRateConfig $rate): ShippingMethod => $rate->method,
            $methods,
        ));

        // Digital is always available
        if (!in_array(ShippingMethod::Digital, $result, true)) {
            $result[] = ShippingMethod::Digital;
        }

        return $result;
    }

    /**
     * @param list<array{productId: string, amount: int, quantity: int, digital: bool}> $items
     */
    private function allDigital(array $items): bool
    {
        return array_all($items, static fn(array $item): bool => (bool) $item['digital']);
    }

    /**
     * @param list<array{productId: string, amount: int, quantity: int, digital: bool}> $items
     */
    private function calculateSubtotal(array $items): int
    {
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += $item['amount'] * $item['quantity'];
        }

        return $subtotal;
    }

    /**
     * @param list<array{productId: string, amount: int, quantity: int, digital: bool}> $items
     */
    private function physicalItemCount(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (!$item['digital']) {
                $count += $item['quantity'];
            }
        }

        return $count;
    }

    private function findRate(string $country): ?ShippingRateConfig
    {
        $method = ShippingMethod::Standard;

        // First: exact country match for the standard method
        $exact = array_find(
            $this->config->shippingRates,
            static fn(ShippingRateConfig $rate): bool => $rate->method === $method && $rate->countryCodes !== [] && in_array($country, $rate->countryCodes, true),
        );

        if ($exact !== null) {
            return $exact;
        }

        // Second: wildcard (empty country list) for the standard method
        return array_find(
            $this->config->shippingRates,
            static fn(ShippingRateConfig $rate): bool => $rate->method === $method && $rate->countryCodes === [],
        );
    }
}
