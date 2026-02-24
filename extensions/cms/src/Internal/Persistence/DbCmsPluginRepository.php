<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Plugins\CmsPluginRepositoryInterface;
use Pulsar\Extension\Cms\Plugins\InstalledCmsPlugin;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use CmsPluginRepositoryInterface for public API')]
final readonly class DbCmsPluginRepository implements CmsPluginRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_installed_plugins WHERE id = :id AND deleted_at IS NULL
        SQL;

    private const string SQL_FIND_BY_SLUG = <<<'SQL'
        SELECT * FROM cms_installed_plugins
        WHERE slug = :slug AND deleted_at IS NULL
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT * FROM cms_installed_plugins
        WHERE deleted_at IS NULL
        ORDER BY installed_at DESC
        SQL;

    private const string SQL_FIND_ENABLED = <<<'SQL'
        SELECT * FROM cms_installed_plugins
        WHERE is_enabled = true AND deleted_at IS NULL
        ORDER BY boot_order ASC
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'slug', 'display_name', 'version', 'description',
        'author_name', 'author_url', 'license', 'manifest_hash', 'package_hash',
        'provenance_verified', 'signature_verified', 'capabilities', 'boot_order',
        'is_enabled', 'storage_path', 'installed_at', 'installed_by',
        'enabled_at', 'enabled_by', 'disabled_at', 'deleted_at',
    ];

    private const array UPSERT_UPDATE = [
        'display_name', 'version', 'description',
        'author_name', 'author_url', 'license',
        'manifest_hash', 'package_hash',
        'provenance_verified', 'signature_verified',
        'capabilities', 'boot_order',
        'is_enabled', 'storage_path',
        'enabled_at', 'enabled_by',
        'disabled_at', 'deleted_at',
    ];

    private const string SQL_DELETE = <<<'SQL'
        UPDATE cms_installed_plugins SET deleted_at = :deleted_at WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?InstalledCmsPlugin
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findBySlug(string $slug, ?string $tenantId = null): ?InstalledCmsPlugin
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

    public function findEnabled(?string $tenantId = null): array
    {
        $sql = self::SQL_FIND_ENABLED;
        $bindings = [];

        if ($tenantId !== null) {
            $sql = str_replace(
                'WHERE is_enabled = true',
                'WHERE is_enabled = true AND tenant_id = :tenant_id',
                $sql,
            );
            $bindings['tenant_id'] = $tenantId;
        }

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function save(InstalledCmsPlugin $plugin): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_installed_plugins',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $plugin->id,
            'tenant_id' => $plugin->tenantId,
            'slug' => $plugin->slug,
            'display_name' => $plugin->displayName,
            'version' => $plugin->version,
            'description' => $plugin->description,
            'author_name' => $plugin->authorName,
            'author_url' => $plugin->authorUrl,
            'license' => $plugin->license,
            'manifest_hash' => $plugin->manifestHash,
            'package_hash' => $plugin->packageHash,
            'provenance_verified' => $plugin->provenanceVerified,
            'signature_verified' => $plugin->signatureVerified,
            'capabilities' => json_encode($plugin->capabilities, JSON_THROW_ON_ERROR),
            'boot_order' => $plugin->bootOrder,
            'is_enabled' => $plugin->isEnabled,
            'storage_path' => $plugin->storagePath,
            'installed_at' => $plugin->installedAt->format('c'),
            'installed_by' => $plugin->installedBy,
            'enabled_at' => $plugin->enabledAt?->format('c'),
            'enabled_by' => $plugin->enabledBy,
            'disabled_at' => $plugin->disabledAt?->format('c'),
            'deleted_at' => $plugin->deletedAt?->format('c'),
        ]);
    }

    public function delete(string $pluginId): void
    {
        $this->connection->execute(self::SQL_DELETE, [
            'id' => $pluginId,
            'deleted_at' => new DateTimeImmutable()->format('c'),
        ]);
    }

    private static function hydrate(Row $row): InstalledCmsPlugin
    {
        /** @var list<string> $capabilities */
        $capabilities = json_decode($row->getString('capabilities'), true, 512, JSON_THROW_ON_ERROR);

        return new InstalledCmsPlugin(
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
            capabilities: $capabilities,
            bootOrder: $row->getInt('boot_order'),
            isEnabled: $row->getBool('is_enabled'),
            storagePath: $row->getString('storage_path'),
            installedAt: new DateTimeImmutable($row->getString('installed_at')),
            installedBy: $row->getString('installed_by'),
            enabledAt: self::toDateTime($row->getNullableString('enabled_at')),
            enabledBy: $row->getNullableString('enabled_by'),
            disabledAt: self::toDateTime($row->getNullableString('disabled_at')),
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
