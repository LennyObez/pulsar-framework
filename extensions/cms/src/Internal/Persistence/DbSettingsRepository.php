<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Settings\SiteSetting;

use function array_map;
use function implode;
use function is_scalar;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository; use SettingsServiceInterface for public API')]
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
        ORDER BY CASE WHEN locale IS NULL THEN 1 ELSE 0 END, locale DESC
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

    private const array UPSERT_UPDATE = [
        'value', 'value_type', 'updated_at', 'updated_by',
    ];

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
        $sql = self::compileUpsert($this->connection->driver());

        $this->connection->execute($sql, [
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

    private static function compileUpsert(Driver $driver): string
    {
        $setClauses = implode(', ', array_map(
            static fn(string $col): string => match ($driver) {
                Driver::MySQL => sprintf('%s = VALUES(%s)', $col, $col),
                Driver::PostgreSQL, Driver::SQLite => sprintf('%s = EXCLUDED.%s', $col, $col),
            },
            self::UPSERT_UPDATE,
        ));

        $q = match ($driver) {
            Driver::MySQL => '`',
            Driver::PostgreSQL, Driver::SQLite => '"',
        };

        $insert = 'INSERT INTO cms_site_settings'
            . ' (id, tenant_id, ' . $q . 'group' . $q . ', ' . $q . 'key' . $q
            . ', locale, value, value_type, updated_at, updated_by)'
            . ' VALUES (:id, :tenant_id, :group, :key, :locale, :value, :value_type, :updated_at, :updated_by)';

        return match ($driver) {
            Driver::PostgreSQL, Driver::SQLite => $insert
                . ' ON CONFLICT (COALESCE(tenant_id, \'00000000-0000-0000-0000-000000000000\'), '
                . $q . 'group' . $q . ', ' . $q . 'key' . $q
                . ', COALESCE(locale, \'\')) DO UPDATE SET ' . $setClauses,
            Driver::MySQL => $insert . ' ON DUPLICATE KEY UPDATE ' . $setClauses,
        };
    }

    public function decodeValue(SiteSetting $setting): mixed
    {
        return match ($setting->valueType) {
            'int' => (int) $setting->value,
            'float' => (float) $setting->value,
            'bool' => $setting->value === 'true' || $setting->value === '1',
            'json' => json_decode($setting->value, true, flags: JSON_THROW_ON_ERROR),
            default => $setting->value,
        };
    }

    public static function encodeValue(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'int' => (string) (is_numeric($value) ? (int) $value : 0),
            'float' => (string) (is_numeric($value) ? (float) $value : 0.0),
            'bool' => $value ? 'true' : 'false',
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            default => is_scalar($value) ? (string) $value : '',
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
