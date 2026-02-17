<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Domain;

use Pulsar\Api\Api;

/**
 * Service category for organizing bookable services.
 */
#[Api(since: '1.0.0')]
final readonly class ServiceCategory
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public int $sortOrder,
    ) {}
}
