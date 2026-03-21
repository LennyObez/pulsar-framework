<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\Site;

/**
 * Persistence interface for analytics site records.
 * @api
 */
#[Api(since: '1.0.0')]
interface SiteRepositoryInterface
{
    public function findById(string $id): ?Site;

    public function findByTrackingId(string $trackingId): ?Site;

    public function findByDomain(string $domain): ?Site;

    /**
     * @return list<Site>
     */
    public function findAll(): array;

    public function save(Site $site): void;

    public function delete(string $id): void;
}
