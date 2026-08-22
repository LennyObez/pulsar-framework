<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Seo;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Content\Redirect;
use Pulsar\Extension\Cms\Exception\CmsException;

/**
 * Manages URL redirects with chain collapse, open redirect protection, and bulk import.
 *
 * @psalm-api Public binding contract; implemented by RedirectManager and
 *            consumed by admin redirect controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface RedirectManagerInterface
{
    /**
     * Resolve a path to its final redirect destination, collapsing any chains.
     *
     * Returns null if no redirect exists for the path.
     */
    public function resolve(string $path, ?string $locale = null, ?string $tenantId = null): ?Redirect;

    /**
     * Create a new redirect, validating the target URL for open redirect attacks.
     *
     * @throws CmsException If the target URL is unsafe (open redirect)
     */
    public function create(
        string $fromPath,
        string $toPath,
        int $statusCode,
        string $createdBy,
        string $reason,
        ?string $locale = null,
        ?string $tenantId = null,
    ): Redirect;

    /**
     * Delete a redirect by ID.
     *
     * @throws CmsException If the redirect is not found
     */
    public function delete(string $redirectId): void;

    /**
     * Import redirects from a CSV string.
     *
     * CSV format: from_path,to_path,status_code
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function importCsv(string $csvContent, string $createdBy, string $reason, ?string $tenantId = null): array;

    /**
     * List all redirects with pagination.
     *
     * @return list<Redirect>
     */
    public function listAll(int $page = 1, int $perPage = 50, ?string $tenantId = null): array;
}
