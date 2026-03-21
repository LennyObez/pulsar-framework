<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Docs;

use Pulsar\Api\Api;

/**
 * Service interface for managing documentation versions.
 *
 * @psalm-api Public binding contract; implemented by DocVersionService and
 *            consumed by docs admin controllers.
 * @api
 */
#[Api(since: '1.0.0')]
interface DocVersionServiceInterface
{
    /**
     * List all available documentation versions.
     *
     * @return list<array{slug: string, label: string, is_default: bool, is_archived: bool}>
     */
    public function listVersions(): array;

    /**
     * Get the default version slug, or null if none is set.
     */
    public function getDefaultVersion(): ?string;

    /**
     * Set the default documentation version.
     */
    public function setDefaultVersion(string $versionSlug): void;
}
