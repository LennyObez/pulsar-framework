<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Settings;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;

/**
 * Site settings service with typed value serialization, per-locale cascading,
 * and full audit trail for configuration changes.
 */
#[Internal]
final readonly class SettingsService implements SettingsServiceInterface
{
    public function __construct(
        private ConnectionInterface $db,
        private AuditLoggerInterface $auditLogger,
        private ?string $tenantId = null,
    ) {}

    public function get(string $group, string $key, ?string $locale = null): mixed
    {
        // Try locale-specific first, then fall back to global
        $setting = $this->findSetting($group, $key, $locale);

        if ($setting === null && $locale !== null) {
            $setting = $this->findSetting($group, $key, null);
        }

        if ($setting === null) {
            return null;
        }

        return $this->deserializeValue($setting->value, $setting->valueType);
    }

    public function set(
        string $group,
        string $key,
        mixed $value,
        ?string $locale = null,
        ?string $reason = null,
    ): void {
        $valueType = $this->detectValueType($value);
        $serialized = $this->serializeValue($value, $valueType);
        $now = new DateTimeImmutable();

        $existing = $this->findSetting($group, $key, $locale);

        if ($existing !== null) {
            $this->db->execute(
                <<<'SQL'
                    UPDATE cms_site_settings
                    SET value = :value, value_type = :value_type, updated_at = :updated_at, updated_by = :updated_by
                    WHERE id = :id
                    SQL,
                [
                    'value' => $serialized,
                    'value_type' => $valueType,
                    'updated_at' => $now->format('c'),
                    'updated_by' => 'system',
                    'id' => $existing->id,
                ],
            );
        } else {
            $id = UuidGenerator::v7();

            $this->db->execute(
                <<<'SQL'
                    INSERT INTO cms_site_settings (id, tenant_id, "group", key, locale, value, value_type, updated_at, updated_by)
                    VALUES (:id, :tenant_id, :group, :key, :locale, :value, :value_type, :updated_at, :updated_by)
                    SQL,
                [
                    'id' => $id,
                    'tenant_id' => $this->tenantId,
                    'group' => $group,
                    'key' => $key,
                    'locale' => $locale,
                    'value' => $serialized,
                    'value_type' => $valueType,
                    'updated_at' => $now->format('c'),
                    'updated_by' => 'system',
                ],
            );
        }

        $this->auditLogger->log(
            AuditEvent::ConfigurationChange,
            AuditOutcome::Success,
            null,
            'cms.settings.updated',
            "setting:$group.$key",
            [
                'group' => $group,
                'key' => $key,
                'locale' => $locale,
                'reason' => $reason,
            ],
        );
    }

    public function getGroup(string $group, ?string $locale = null): array
    {
        $sql = 'SELECT key, value, value_type FROM cms_site_settings WHERE "group" = :group';
        $bindings = ['group' => $group];

        if ($this->tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        } else {
            $sql .= ' AND tenant_id IS NULL';
        }

        // Get global settings first
        $globalSql = $sql . ' AND locale IS NULL';
        $globalResult = $this->db->query($globalSql, $bindings);

        $settings = [];

        foreach ($globalResult->rows as $row) {
            $k = $row->getString('key');
            $settings[$k] = $this->deserializeValue(
                $row->getString('value'),
                $row->getString('value_type'),
            );
        }

        // Override with locale-specific settings if requested
        if ($locale !== null) {
            $localeSql = $sql . ' AND locale = :locale';
            $bindings['locale'] = $locale;
            $localeResult = $this->db->query($localeSql, $bindings);

            foreach ($localeResult->rows as $row) {
                $k = $row->getString('key');
                $settings[$k] = $this->deserializeValue(
                    $row->getString('value'),
                    $row->getString('value_type'),
                );
            }
        }

        return $settings;
    }

    public function getAll(?string $locale = null): array
    {
        $sql = 'SELECT "group", key, value, value_type, locale FROM cms_site_settings WHERE 1=1';
        $bindings = [];

        if ($this->tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        } else {
            $sql .= ' AND tenant_id IS NULL';
        }

        $sql .= ' AND (locale IS NULL';

        if ($locale !== null) {
            $sql .= ' OR locale = :locale';
            $bindings['locale'] = $locale;
        }

        $sql .= ')';
        $sql .= ' ORDER BY "group", key, locale NULLS FIRST';

        $result = $this->db->query($sql, $bindings);
        $settings = [];

        foreach ($result->rows as $row) {
            $g = $row->getString('group');
            $k = $row->getString('key');

            // Locale-specific values override global (they come after NULLS FIRST)
            $settings[$g][$k] = $this->deserializeValue(
                $row->getString('value'),
                $row->getString('value_type'),
            );
        }

        return $settings;
    }

    private function findSetting(string $group, string $key, ?string $locale): ?SiteSetting
    {
        $sql = 'SELECT id, tenant_id, "group", key, locale, value, value_type, updated_at, updated_by FROM cms_site_settings WHERE "group" = :group AND key = :key';
        $bindings = ['group' => $group, 'key' => $key];

        if ($this->tenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        } else {
            $sql .= ' AND tenant_id IS NULL';
        }

        if ($locale !== null) {
            $sql .= ' AND locale = :locale';
            $bindings['locale'] = $locale;
        } else {
            $sql .= ' AND locale IS NULL';
        }

        $sql .= ' LIMIT 1';

        $row = $this->db->query($sql, $bindings)->first();

        if ($row === null) {
            return null;
        }

        return new SiteSetting(
            id: $row->getString('id'),
            tenantId: $row->get('tenant_id') !== null ? $row->getString('tenant_id') : null,
            group: $row->getString('group'),
            key: $row->getString('key'),
            locale: $row->get('locale') !== null ? $row->getString('locale') : null,
            value: $row->getString('value'),
            valueType: $row->getString('value_type'),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
            updatedBy: $row->getString('updated_by'),
        );
    }

    private function serializeValue(mixed $value, string $valueType): string
    {
        return match ($valueType) {
            'bool' => $value ? 'true' : 'false',
            'int', 'float', 'string' => is_scalar($value) ? (string) $value : '',
            default => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }

    private function deserializeValue(string $serialized, string $valueType): mixed
    {
        return match ($valueType) {
            'bool' => $serialized === 'true',
            'int' => (int) $serialized,
            'float' => (float) $serialized,
            'string' => $serialized,
            default => json_decode($serialized, true, flags: JSON_THROW_ON_ERROR),
        };
    }

    private function detectValueType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            default => 'json',
        };
    }

}
