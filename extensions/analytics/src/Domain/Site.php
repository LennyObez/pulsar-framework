<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Domain;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A tracked website in the analytics system.
 *
 * Each site has a unique domain and tracking ID used to identify
 * which website analytics data belongs to.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Site
{
    /**
     * @param string $id UUIDv7
     * @param string $domain Canonical domain (e.g., 'example.com')
     * @param string $name Human-readable site name
     * @param string $trackingId Unique tracking identifier (e.g., 'plsr_a1b2c3d4')
     * @param string $timezone IANA timezone for reporting (e.g., 'America/New_York')
     * @param array<string, mixed> $settings Site-specific settings
     */
    public function __construct(
        public string $id,
        public string $domain,
        public string $name,
        public string $trackingId,
        public string $timezone = 'UTC',
        public array $settings = [],
        public DateTimeImmutable $createdAt = new DateTimeImmutable(),
        public DateTimeImmutable $updatedAt = new DateTimeImmutable(),
    ) {}
}
