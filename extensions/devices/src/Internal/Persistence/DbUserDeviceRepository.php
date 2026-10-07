<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;

/**
 * Database-backed repository for user device persistence.
 *
 * Uses portable upsert queries for save operations and BLAKE2b token hash
 * lookups via a unique index on api_token_hash.
 */
#[Internal(reason: 'Raw-DB repository; use UserDeviceRepositoryInterface for public API')]
final readonly class DbUserDeviceRepository implements UserDeviceRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT d.*
        FROM user_devices d
        WHERE d.id = :id
        SQL;

    private const string SQL_FIND_BY_USER = <<<'SQL'
        SELECT d.*
        FROM user_devices d
        WHERE d.user_id = :user_id
        ORDER BY d.created_at DESC
        SQL;

    private const string SQL_FIND_BY_TOKEN_HASH = <<<'SQL'
        SELECT d.*
        FROM user_devices d
        WHERE d.api_token_hash = :hash
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM user_devices WHERE id = :id
        SQL;

    private const string SQL_COUNT_BY_USER = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM user_devices
        WHERE user_id = :user_id
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'user_id', 'device_name', 'platform', 'app_version',
        'api_token_hash', 'last_seen_at', 'created_at',
    ];

    private const array UPSERT_UPDATE = [
        'device_name', 'platform', 'app_version',
        'api_token_hash', 'last_seen_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(UserDevice $device): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'user_devices',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $device->id,
            'user_id' => $device->userId,
            'device_name' => $device->deviceName,
            'platform' => $device->platform->value,
            'app_version' => $device->appVersion,
            'api_token_hash' => $device->apiTokenHash,
            'last_seen_at' => $device->lastSeenAt?->format('c'),
            'created_at' => $device->createdAt->format('c'),
        ]);
    }

    public function findById(string $id): ?UserDevice
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByUser(string $userId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_USER, [
            'user_id' => $userId,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function findByTokenHash(string $hash): ?UserDevice
    {
        $result = $this->connection->query(self::SQL_FIND_BY_TOKEN_HASH, [
            'hash' => $hash,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    public function countByUser(string $userId): int
    {
        $result = $this->connection->query(self::SQL_COUNT_BY_USER, [
            'user_id' => $userId,
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    private static function hydrate(Row $row): UserDevice
    {
        return new UserDevice(
            id: $row->getString('id'),
            userId: $row->getString('user_id'),
            deviceName: $row->getString('device_name'),
            platform: Platform::from($row->getString('platform')),
            appVersion: $row->getString('app_version'),
            apiTokenHash: $row->getString('api_token_hash'),
            lastSeenAt: self::toDateTime($row->getNullableString('last_seen_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
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
