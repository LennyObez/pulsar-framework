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
 * @phpstan-type EntityType 'content'|'taxonomies'|'menus'|'settings'|'media_refs'
 */
#[Api(since: '1.0.0')]
final readonly class ExportOptions
{
    private const array VALID_SCOPES = ['content', 'taxonomies', 'menus', 'settings', 'media_refs'];

    /**
     * @param list<string> $scope Entity types to include in the export
     * @param list<string>|null $locales BCP 47 locale codes to filter by, null for all
     * @param bool $includePii Whether to include PII fields in the export
     * @param string|null $tenantId Tenant scope, null for all tenants
     */
    public function __construct(
        public array $scope,
        public ?array $locales = null,
        public bool $includePii = false,
        public ?string $tenantId = null,
    ) {}

    /**
     * @param array{
     *     scope: list<string>,
     *     locales?: list<string>|null,
     *     include_pii?: bool,
     *     tenant_id?: string|null,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $scope = array_values(array_intersect($data['scope'], self::VALID_SCOPES));
        $invalid = array_diff($data['scope'], self::VALID_SCOPES);

        if ($scope === [] || count($invalid) > 0) {
            $validList = implode(', ', self::VALID_SCOPES);

            throw new InvalidArgumentException(
                "Invalid export scope. Valid types: {$validList}",
            );
        }

        return new self(
            scope: $scope,
            locales: $data['locales'] ?? null,
            includePii: $data['include_pii'] ?? false,
            tenantId: $data['tenant_id'] ?? null,
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
        ];
    }
}
