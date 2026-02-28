<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use Pulsar\Api\Api;

/**
 * Defines which CMS data categories to include in a backup.
 *
 * @psalm-api Public DTO passed to BackupServiceInterface::createBackup().
 */
#[Api(since: '1.0.0')]
final readonly class BackupScope
{
    public function __construct(
        public bool $includeContent = true,
        public bool $includeMedia = false,
        public bool $includeTaxonomies = true,
        public bool $includeMenus = true,
        public bool $includeSettings = true,
        public bool $includeCommerce = true,
        public ?string $tenantId = null,
    ) {}

    /**
     * @param array{
     *     include_content?: bool,
     *     include_media?: bool,
     *     include_taxonomies?: bool,
     *     include_menus?: bool,
     *     include_settings?: bool,
     *     include_commerce?: bool,
     *     tenant_id?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            includeContent: $data['include_content'] ?? true,
            includeMedia: $data['include_media'] ?? false,
            includeTaxonomies: $data['include_taxonomies'] ?? true,
            includeMenus: $data['include_menus'] ?? true,
            includeSettings: $data['include_settings'] ?? true,
            includeCommerce: $data['include_commerce'] ?? true,
            tenantId: $data['tenant_id'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'include_content' => $this->includeContent,
            'include_media' => $this->includeMedia,
            'include_taxonomies' => $this->includeTaxonomies,
            'include_menus' => $this->includeMenus,
            'include_settings' => $this->includeSettings,
            'include_commerce' => $this->includeCommerce,
            'tenant_id' => $this->tenantId,
        ];
    }
}
