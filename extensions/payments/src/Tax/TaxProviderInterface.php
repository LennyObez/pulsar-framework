<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tax;

use Pulsar\Api\Api;

/**
 * Pluggable tax calculation provider.
 *
 * Implementations can integrate with external tax APIs
 * (e.g., Avalara, TaxJar, Vertex) or provide custom logic.
 * @api
 */
#[Api(since: '1.0.0')]
interface TaxProviderInterface
{
    /**
     * Calculate tax for a given amount, customer address, and optional product type.
     */
    public function calculateTax(TaxCalculationRequest $request): TaxCalculationResult;

    /**
     * Validate a tax exemption certificate or ID.
     */
    public function validateExemption(string $taxId, string $countryCode): bool;
}
