<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\ApiKey;
use Pulsar\Extension\Cms\Commerce\ApiKeyRepositoryInterface;

/**
 * Database-backed API key repository.
 */
#[Internal(reason: 'Use ApiKeyRepositoryInterface for public API')]
final readonly class DbApiKeyRepository implements ApiKeyRepositoryInterface
{
    private const string SQL_FIND_BY_KEY_HASH = <<<'SQL'
        SELECT * FROM cms_api_keys WHERE key_hash = :key_hash AND is_active = 1 LIMIT 1
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'tenant_id', 'name', 'key_hash', 'last_used_at', 'is_active', 'created_at', 'expires_at',
    ];

    private const array UPSERT_UPDATE = ['name', 'is_active', 'expires_at'];

    private const string SQL_RECORD_USAGE = <<<'SQL'
        UPDATE cms_api_keys SET last_used_at = :now WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByKeyHash(string $keyHash): ?ApiKey
    {
        $row = $this->db->query(self::SQL_FIND_BY_KEY_HASH, ['key_hash' => $keyHash])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function save(ApiKey $key): void
    {
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_api_keys',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->db->execute($sql, [
            'id' => $key->id,
            'tenant_id' => $key->tenantId,
            'name' => $key->name,
            'key_hash' => $key->keyHash,
            'last_used_at' => $key->lastUsedAt,
            'is_active' => $key->isActive ? 1 : 0,
            'created_at' => $key->createdAt->format('c'),
            'expires_at' => $key->expiresAt?->format('c'),
        ]);
    }

    public function recordUsage(string $id): void
    {
        $this->db->execute(self::SQL_RECORD_USAGE, [
            'id' => $id,
            'now' => new DateTimeImmutable()->format('c'),
        ]);
    }

    private static function hydrate(Row $row): ApiKey
    {
        return new ApiKey(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            name: $row->getString('name'),
            keyHash: $row->getString('key_hash'),
            lastUsedAt: $row->getNullableString('last_used_at'),
            isActive: (bool) $row->getInt('is_active'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            expiresAt: self::toDateTime($row->getNullableString('expires_at')),
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
