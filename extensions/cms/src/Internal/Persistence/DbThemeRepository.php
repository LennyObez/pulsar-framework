<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Themes\InstalledTheme;
use Pulsar\Extension\Cms\Themes\ThemeRepositoryInterface;

/**
 * @psalm-api Bound to ThemeRepositoryInterface in the CMS service provider;
 *            resolved from the DI container, never instantiated by name.
 */
#[Internal(reason: 'Raw-DB repository; use ThemeRepositoryInterface for public API')]
final readonly class DbThemeRepository implements ThemeRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_installed_themes WHERE id = :id AND deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT * FROM cms_installed_themes
        WHERE slug = :slug AND deleted_at IS NULL
        SQL;

    private const string SQL_FIND_ACTIVE = <<<'SQL'
        SELECT * FROM cms_installed_themes
        WHERE is_active = true AND deleted_at IS NULL
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT * FROM cms_installed_themes
        WHERE deleted_at IS NULL
        ORDER BY installed_at DESC
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'slug', 'display_name', 'version', 'description',
        'author_name', 'author_url', 'license', 'manifest_hash', 'package_hash',
        'provenance_verified', 'signature_verified', 'is_active', 'storage_path',
        'installed_at', 'installed_by', 'activated_at', 'activated_by',
        'deactivated_at', 'deleted_at',
    ];

    private const array UPSERT_UPDATE = [
        'display_name', 'version', 'description',
        'author_name', 'author_url', 'license',
        'manifest_hash', 'package_hash',
        'provenance_verified', 'signature_verified',
        'is_active', 'storage_path',
        'activated_at', 'activated_by',
        'deactivated_at', 'deleted_at',
    ];

    private const string SQL_DELETE = <<<'SQL'
        UPDATE cms_installed_themes SET deleted_at = :deleted_at WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?InstalledTheme
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledTheme
    {
        $sql = self::SQL_FIND_BY_SLUG;
        $bindings = ['slug' => $slug];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        } else {
            $sql .= ' AND tenant_id IS NULL';
        }

        $sql .= ' LIMIT 1';

        $result = $this->connection->query($sql, $bindings);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findActive(?string $tenantId = null): ?InstalledTheme
    {
        $sql = self::SQL_FIND_ACTIVE;
        $bindings = [];

        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $tenantId;
        } else {
            $sql .= ' AND tenant_id IS NULL';
        }

        $sql .= ' LIMIT 1';

        $result = $this->connection->query($sql, $bindings);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findAll(?string $tenantId = null): array
    {
        $sql = self::SQL_FIND_ALL;
        $bindings = [];

        if ($tenantId !== null) {
            $sql = str_replace(
                'WHERE deleted_at IS NULL',
                'WHERE deleted_at IS NULL AND tenant_id = :tenant_id',
                $sql,
            );
            $bindings['tenant_id'] = $tenantId;
        }

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function save(InstalledTheme $theme): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_installed_themes',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $theme->id,
            'tenant_id' => $theme->tenantId,
            'slug' => $theme->slug,
            'display_name' => $theme->displayName,
            'version' => $theme->version,
            'description' => $theme->description,
            'author_name' => $theme->authorName,
            'author_url' => $theme->authorUrl,
            'license' => $theme->license,
            'manifest_hash' => $theme->manifestHash,
            'package_hash' => $theme->packageHash,
            'provenance_verified' => $theme->provenanceVerified,
            'signature_verified' => $theme->signatureVerified,
            'is_active' => $theme->isActive,
            'storage_path' => $theme->storagePath,
            'installed_at' => $theme->installedAt->format('c'),
            'installed_by' => $theme->installedBy,
            'activated_at' => $theme->activatedAt?->format('c'),
            'activated_by' => $theme->activatedBy,
            'deactivated_at' => $theme->deactivatedAt?->format('c'),
            'deleted_at' => $theme->deletedAt?->format('c'),
        ]);
    }

    public function delete(string $themeId): void
    {
        $this->connection->execute(self::SQL_DELETE, [
            'id' => $themeId,
            'deleted_at' => new DateTimeImmutable()->format('c'),
        ]);
    }

    private static function hydrate(Row $row): InstalledTheme
    {
        return new InstalledTheme(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            slug: $row->getString('slug'),
            displayName: $row->getString('display_name'),
            version: $row->getString('version'),
            description: $row->getNullableString('description'),
            authorName: $row->getNullableString('author_name'),
            authorUrl: $row->getNullableString('author_url'),
            license: $row->getNullableString('license'),
            manifestHash: $row->getString('manifest_hash'),
            packageHash: $row->getString('package_hash'),
            provenanceVerified: $row->getBool('provenance_verified'),
            signatureVerified: $row->getBool('signature_verified'),
            isActive: $row->getBool('is_active'),
            storagePath: $row->getString('storage_path'),
            installedAt: new DateTimeImmutable($row->getString('installed_at')),
            installedBy: $row->getString('installed_by'),
            activatedAt: self::toDateTime($row->getNullableString('activated_at')),
            activatedBy: $row->getNullableString('activated_by'),
            deactivatedAt: self::toDateTime($row->getNullableString('deactivated_at')),
            deletedAt: self::toDateTime($row->getNullableString('deleted_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
