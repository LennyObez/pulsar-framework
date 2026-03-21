<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Persistence contract for release entities.
 *
 * Implementations must support upsert semantics, pagination with optional
 * platform and beta filtering, and lookup of the latest stable release
 * per platform.
 * @api
 */
#[Api(since: '1.0.0')]
interface ReleaseRepositoryInterface
{
    /**
     * Persist a release entity (insert or update).
     */
    public function save(Release $release): void;

    /**
     * Find a release by its identifier.
     */
    public function findById(string $id): ?Release;

    /**
     * Find the most recent stable release for a given platform.
     */
    public function findLatestStable(ReleasePlatform $platform): ?Release;

    /**
     * Find all releases with optional platform and beta filters, paginated.
     *
     * @return PaginationResult<Release>
     */
    public function findAll(
        int $page,
        int $perPage,
        ?ReleasePlatform $platform = null,
        ?bool $includeBeta = null,
    ): PaginationResult;
}
