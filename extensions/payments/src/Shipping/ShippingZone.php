<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Shipping;

use Pulsar\Api\Api;

use function array_filter;
use function array_values;
use function in_array;
use function strtoupper;

/**
 * A geographic shipping zone grouping countries with their available methods.
 *
 * Zones enable region-specific shipping rates (e.g., "EU Zone" with
 * standard and express methods, "Rest of World" with only standard).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ShippingZone
{
    /**
     * @param non-empty-string $id Unique zone identifier
     * @param non-empty-string $name Human-readable zone name (e.g., "Europe", "North America")
     * @param list<string> $countries ISO 3166-1 alpha-2 country codes in this zone
     * @param list<ShippingMethodRate> $methods Available shipping methods with rates for this zone
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $countries,
        public array $methods,
    ) {}

    /**
     * Check whether a country belongs to this zone.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 code
     */
    public function containsCountry(string $countryCode): bool
    {
        return in_array(strtoupper($countryCode), $this->countries, true);
    }

    /**
     * Get only the enabled shipping methods for this zone.
     *
     * @return list<ShippingMethodRate>
     */
    public function enabledMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn(ShippingMethodRate $m): bool => $m->enabled,
        ));
    }

    /**
     * Find a specific method rate by its method ID.
     */
    public function findMethod(string $methodId): ?ShippingMethodRate
    {
        foreach ($this->methods as $method) {
            if ($method->methodId === $methodId) {
                return $method;
            }
        }

        return null;
    }
}
