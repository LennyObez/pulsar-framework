<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Shipping;

use Pulsar\Api\Api;

/**
 * Repository for shipping zone CRUD operations.
 *
 * Shipping zones define geographic regions with their available
 * shipping methods and rates. A single country may belong to
 * multiple zones; the zone with the most specific match wins.
 */
#[Api(since: '1.0.0')]
interface ShippingZoneRepositoryInterface
{
    /**
     * Find a shipping zone by its ID.
     */
    public function findById(string $id): ?ShippingZone;

    /**
     * Find all zones that contain a given country.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 code
     * @return list<ShippingZone>
     */
    public function findByCountry(string $countryCode): array;

    /**
     * List all shipping zones, ordered by name.
     *
     * @return list<ShippingZone>
     */
    public function listAll(): array;

    /**
     * Persist a shipping zone (insert or update).
     */
    public function save(ShippingZone $zone): void;

    /**
     * Delete a shipping zone by its ID.
     *
     * @return bool True if deleted, false if zone was not found
     */
    public function delete(string $id): bool;
}
