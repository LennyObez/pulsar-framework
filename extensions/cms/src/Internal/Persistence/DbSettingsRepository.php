<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Settings\SiteSetting;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use SettingsServiceInterface for public API')]
final readonly class DbSettingsRepository
{
    private const string SENTINEL_TENANT = '00000000-0000-0000-0000-000000000000';

    private const string SQL_FIND = <<<'SQL'
        SELECT * FROM cms_site_settings
        WHERE COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
          AND "group" = :group
          AND key = :key
        SQL;

    private const string SQL_FIND_WITH_LOCALE = <<<'SQL'
        SELECT * FROM cms_site_settings
        WHERE COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
          AND "group" = :group
          AND key = :key
          AND (locale = :locale OR locale IS NULL)
        ORDER BY locale DESC NULLS LAST
        LIMIT 1
        SQL;

    private const string SQL_FIND_GROUP = <<<'SQL'
        SELECT * FROM cms_site_settings
        WHERE COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
          AND "group" = :group
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT * FROM cms_site_settings
        WHERE COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000') = :tenant_key
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_site_settings (
            id, tenant_id, "group", key, locale, value, value_type, updated_at, updated_by
        ) VALUES (
            :id, :tenant_id, :group, :key, :locale, :value, :value_type, :updated_at, :updated_by
        )
        ON CONFLICT (
            COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'),
            "group", key, COALESCE(locale, '')
        ) DO UPDATE SET
            value = EXCLUDED.value,
            value_type = EXCLUDED.value_type,
            updated_at = EXCLUDED.updated_at,
            updated_by = EXCLUDED.updated_by
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
        private ?string $tenantId,
    ) {}

    public function findSetting(string $group, string $key, ?string $locale = null): ?SiteSetting
    {
        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;

        if ($locale !== null) {
            $result = $this->connection->query(self::SQL_FIND_WITH_LOCALE, [
                'tenant_key' => $tenantKey,
                'group' => $group,
                'key' => $key,
                'locale' => $locale,
            ]);
        } else {
            $sql = self::SQL_FIND . ' AND locale IS NULL';
            $result = $this->connection->query($sql, [
                'tenant_key' => $tenantKey,
                'group' => $group,
                'key' => $key,
            ]);
        }

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * @return list<SiteSetting>
     */
    public function findGroup(string $group, ?string $locale = null): array
    {
        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;
        $sql = self::SQL_FIND_GROUP;
        $bindings = ['tenant_key' => $tenantKey, 'group' => $group];

        if ($locale !== null) {
            $sql .= ' AND (locale = :locale OR locale IS NULL)';
            $bindings['locale'] = $locale;
        }

        $sql .= ' ORDER BY key ASC';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    /**
     * @return list<SiteSetting>
     */
    public function findAll(?string $locale = null): array
    {
        $tenantKey = $this->tenantId ?? self::SENTINEL_TENANT;
        $sql = self::SQL_FIND_ALL;
        $bindings = ['tenant_key' => $tenantKey];

        if ($locale !== null) {
            $sql .= ' AND (locale = :locale OR locale IS NULL)';
            $bindings['locale'] = $locale;
        }

        $sql .= ' ORDER BY "group" ASC, key ASC';

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function save(SiteSetting $setting): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $setting->id,
            'tenant_id' => $setting->tenantId,
            'group' => $setting->group,
            'key' => $setting->key,
            'locale' => $setting->locale,
            'value' => $setting->value,
            'value_type' => $setting->valueType,
            'updated_at' => $setting->updatedAt->format('c'),
            'updated_by' => $setting->updatedBy,
        ]);
    }

    public function decodeValue(SiteSetting $setting): mixed
    {
        return match ($setting->valueType) {
            'string' => $setting->value,
            'int' => (int) $setting->value,
            'float' => (float) $setting->value,
            'bool' => $setting->value === 'true' || $setting->value === '1',
            'json' => json_decode($setting->value, true, 512, JSON_THROW_ON_ERROR),
            'encrypted' => $setting->value,
            default => $setting->value,
        };
    }

    public static function encodeValue(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'string' => (string) $value,
            'int' => (string) (int) $value,
            'float' => (string) (float) $value,
            'bool' => $value ? 'true' : 'false',
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            'encrypted' => (string) $value,
            default => (string) $value,
        };
    }

    private static function hydrate(Row $row): SiteSetting
    {
        return new SiteSetting(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            group: $row->getString('group'),
            key: $row->getString('key'),
            locale: $row->getNullableString('locale'),
            value: $row->getString('value'),
            valueType: $row->getString('value_type'),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            updatedBy: $row->getString('updated_by'),
        );
    }
}
