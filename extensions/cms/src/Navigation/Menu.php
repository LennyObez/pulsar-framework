<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A navigation menu assigned to a specific location (e.g., primary, footer).
 */
#[Api(since: '1.0.0')]
final readonly class Menu
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $location Menu location identifier (e.g., primary, footer, sidebar)
     * @param DateTimeImmutable $createdAt Immutable creation timestamp
     * @param string|null $importId Stable import identifier for idempotent imports
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $location,
        public DateTimeImmutable $createdAt,
        public ?string $importId = null,
    ) {}
}
