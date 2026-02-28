<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Installed theme entity: represents a theme package installed in the CMS.
 *
 * @psalm-api Public DTO returned from ThemeRepositoryInterface; consumed
 *            by ThemeManager and admin theme views.
 */
#[Api(since: '1.0.0')]
final readonly class InstalledTheme
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $slug URL-safe theme identifier
     * @param string $displayName Human-readable theme name
     * @param string $version SemVer version string
     * @param string|null $description Theme description
     * @param string|null $authorName Theme author name
     * @param string|null $authorUrl Theme author URL
     * @param string|null $license SPDX license identifier
     * @param string $manifestHash SHA-256 hash of the theme manifest file
     * @param string $packageHash SHA-256 hash of the original theme archive
     * @param bool $provenanceVerified Whether provenance verification passed
     * @param bool $signatureVerified Whether cryptographic signature verification passed
     * @param bool $isActive Whether this theme is the currently active theme
     * @param string $storagePath Relative path within the themes storage directory
     * @param DateTimeImmutable $installedAt When the theme was installed
     * @param string $installedBy UUIDv7 of the user who installed the theme
     * @param DateTimeImmutable|null $activatedAt When the theme was last activated
     * @param string|null $activatedBy UUIDv7 of the user who activated the theme
     * @param DateTimeImmutable|null $deactivatedAt When the theme was last deactivated
     * @param DateTimeImmutable|null $deletedAt Soft delete timestamp
     */
    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $slug,
        public string $displayName,
        public string $version,
        public ?string $description,
        public ?string $authorName,
        public ?string $authorUrl,
        public ?string $license,
        public string $manifestHash,
        public string $packageHash,
        public bool $provenanceVerified,
        public bool $signatureVerified,
        public bool $isActive,
        public string $storagePath,
        public DateTimeImmutable $installedAt,
        public string $installedBy,
        public ?DateTimeImmutable $activatedAt,
        public ?string $activatedBy,
        public ?DateTimeImmutable $deactivatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function activate(string $activatedBy, DateTimeImmutable $now): self
    {
        return clone($this, [
            'isActive' => true,
            'activatedAt' => $now,
            'activatedBy' => $activatedBy,
            'deactivatedAt' => null,
        ]);
    }

    public function deactivate(DateTimeImmutable $now): self
    {
        return clone($this, [
            'isActive' => false,
            'deactivatedAt' => $now,
        ]);
    }

    public function softDelete(DateTimeImmutable $now): self
    {
        return clone($this, [
            'deletedAt' => $now,
        ]);
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
