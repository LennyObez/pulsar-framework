<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tools;

use InvalidArgumentException;
use Pulsar\Api\Api;

use function array_diff;
use function array_intersect;
use function array_values;
use function count;
use function implode;

/**
 * Options controlling a CMS data export operation.
 *
 * @phpstan-type EntityType 'content'|'taxonomies'|'menus'|'settings'|'media_refs'|'comments'|'users'|'media_files'|'configuration'
 */
#[Api(since: '1.0.0')]
final readonly class ExportOptions
{
    private const array VALID_SCOPES = [
        'content',
        'taxonomies',
        'menus',
        'settings',
        'media_refs',
        'comments',
        'users',
        'media_files',
        'configuration',
    ];

    /**
     * @param list<string> $scope Entity types to include in the export
     * @param list<string>|null $locales BCP 47 locale codes to filter by, null for all
     * @param bool $includePii Whether to include PII fields in the export
     * @param string|null $tenantId Tenant scope, null for all tenants
     * @param list<string>|null $contentTypes Filter by content type slugs (e.g. 'page', 'article')
     * @param string|null $dateFrom ISO 8601 date string: include content created on or after this date
     * @param string|null $dateTo ISO 8601 date string: include content created on or before this date
     * @param string|null $status Filter by publishing status (e.g. 'published', 'draft', 'scheduled')
     */
    public function __construct(
        public array $scope,
        public ?array $locales = null,
        public bool $includePii = false,
        public ?string $tenantId = null,
        public ?array $contentTypes = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $status = null,
    ) {
        if ($scope === []) {
            throw new InvalidArgumentException(
                'Export scope must not be empty. Valid types: ' . implode(', ', self::VALID_SCOPES),
            );
        }
    }

    /**
     * @param array{
     *     scope: list<string>,
     *     locales?: list<string>|null,
     *     include_pii?: bool,
     *     tenant_id?: string|null,
     *     content_types?: list<string>|null,
     *     date_from?: string|null,
     *     date_to?: string|null,
     *     status?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $scope = array_values(array_intersect($data['scope'], self::VALID_SCOPES));
        $invalid = array_diff($data['scope'], self::VALID_SCOPES);

        if ($scope === [] || count($invalid) > 0) {
            $validList = implode(', ', self::VALID_SCOPES);

            throw new InvalidArgumentException(
                "Invalid export scope. Valid types: $validList",
            );
        }

        return new self(
            scope: $scope,
            locales: $data['locales'] ?? null,
            includePii: $data['include_pii'] ?? false,
            tenantId: $data['tenant_id'] ?? null,
            contentTypes: $data['content_types'] ?? null,
            dateFrom: $data['date_from'] ?? null,
            dateTo: $data['date_to'] ?? null,
            status: $data['status'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'locales' => $this->locales,
            'include_pii' => $this->includePii,
            'tenant_id' => $this->tenantId,
            'content_types' => $this->contentTypes,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'status' => $this->status,
        ];
    }
}
