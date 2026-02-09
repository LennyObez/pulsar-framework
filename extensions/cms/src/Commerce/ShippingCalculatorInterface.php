<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Commerce;

use Pulsar\Api\Api;

/**
 * Contract for calculating shipping costs and available methods.
 */
#[Api(since: '1.0.0')]
interface ShippingCalculatorInterface
{
    /**
     * Calculate shipping cost for the given items and destination.
     *
     * @param list<array{productId: string, amount: int, quantity: int, digital: bool}> $items
     * @param array<string, mixed> $address Destination address with 'country' key
     */
    public function calculate(array $items, array $address): ShippingResult;

    /**
     * Get available shipping methods for the given destination.
     *
     * @param array<string, mixed> $address Destination address with 'country' key
     * @return list<ShippingMethod>
     */
    public function availableMethods(array $address): array;
}
