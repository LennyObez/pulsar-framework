<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\Site;

/**
 * Manage tracked analytics sites.
 */
#[Api(since: '1.0.0')]
interface SiteServiceInterface
{
    /**
     * @param array<string, mixed> $settings
     */
    public function create(string $domain, string $name, string $timezone = 'UTC', array $settings = []): Site;

    /**
     * @param array<string, mixed> $settings
     */
    public function update(string $id, string $domain, string $name, string $timezone, array $settings = []): Site;

    public function delete(string $id): void;

    public function findById(string $id): ?Site;

    public function findByTrackingId(string $trackingId): ?Site;

    public function findByDomain(string $domain): ?Site;

    /**
     * @return list<Site>
     */
    public function listAll(): array;
}
