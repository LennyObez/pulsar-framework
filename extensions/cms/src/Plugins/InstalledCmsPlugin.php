<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Installed plugin entity: represents a plugin package installed in the CMS.
 *
 * @psalm-api Public DTO returned from CmsPluginRepositoryInterface; consumed
 *            by CmsPluginManager and admin plugin views.
 */
#[Api(since: '1.0.0')]
final readonly class InstalledCmsPlugin
{
    /**
     * @param string $id UUIDv7
     * @param string|null $tenantId UUIDv7, nullable when tenancy disabled
     * @param string $slug URL-safe plugin identifier
     * @param string $displayName Human-readable plugin name
     * @param string $version SemVer version string
     * @param string|null $description Plugin description
     * @param string|null $authorName Plugin author name
     * @param string|null $authorUrl Plugin author URL
     * @param string|null $license SPDX license identifier
     * @param string $manifestHash SHA-256 hash of the plugin manifest file
     * @param string $packageHash SHA-256 hash of the original plugin archive
     * @param bool $provenanceVerified Whether provenance verification passed
     * @param bool $signatureVerified Whether cryptographic signature verification passed
     * @param list<string> $capabilities Declared plugin capabilities
     * @param int $bootOrder Dependency-resolved boot order
     * @param bool $isEnabled Whether this plugin is currently enabled
     * @param string $storagePath Relative path within the plugins storage directory
     * @param DateTimeImmutable $installedAt When the plugin was installed
     * @param string $installedBy UUIDv7 of the user who installed the plugin
     * @param DateTimeImmutable|null $enabledAt When the plugin was last enabled
     * @param string|null $enabledBy UUIDv7 of the user who enabled the plugin
     * @param DateTimeImmutable|null $disabledAt When the plugin was last disabled
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
        public array $capabilities,
        public int $bootOrder,
        public bool $isEnabled,
        public string $storagePath,
        public DateTimeImmutable $installedAt,
        public string $installedBy,
        public ?DateTimeImmutable $enabledAt,
        public ?string $enabledBy,
        public ?DateTimeImmutable $disabledAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    public function enable(string $enabledBy, DateTimeImmutable $now): self
    {
        return clone($this, [
            'isEnabled' => true,
            'enabledAt' => $now,
            'enabledBy' => $enabledBy,
            'disabledAt' => null,
        ]);
    }

    public function disable(DateTimeImmutable $now): self
    {
        return clone($this, [
            'isEnabled' => false,
            'disabledAt' => $now,
        ]);
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
