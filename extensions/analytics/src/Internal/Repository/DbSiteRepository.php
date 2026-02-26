<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Internal\Repository;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Contracts\SiteRepositoryInterface;
use Pulsar\Extension\Analytics\Domain\Site;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use SiteRepositoryInterface for public API')]
final readonly class DbSiteRepository implements SiteRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM analytics_sites WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_TRACKING_ID = <<<'SQL'
        SELECT * FROM analytics_sites WHERE tracking_id = :tracking_id
        SQL;

    private const string SQL_FIND_BY_DOMAIN = <<<'SQL'
        SELECT * FROM analytics_sites WHERE domain = :domain
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT * FROM analytics_sites ORDER BY created_at ASC
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO analytics_sites (id, domain, name, tracking_id, timezone, settings, created_at, updated_at)
        VALUES (:id, :domain, :name, :tracking_id, :timezone, :settings, :created_at, :updated_at)
        ON CONFLICT (id) DO UPDATE SET
            domain = EXCLUDED.domain,
            name = EXCLUDED.name,
            tracking_id = EXCLUDED.tracking_id,
            timezone = EXCLUDED.timezone,
            settings = EXCLUDED.settings,
            updated_at = EXCLUDED.updated_at
        SQL;

    private const string SQL_UPSERT_MYSQL = <<<'SQL'
        INSERT INTO analytics_sites (id, domain, name, tracking_id, timezone, settings, created_at, updated_at)
        VALUES (:id, :domain, :name, :tracking_id, :timezone, :settings, :created_at, :updated_at)
        ON DUPLICATE KEY UPDATE
            domain = VALUES(domain),
            name = VALUES(name),
            tracking_id = VALUES(tracking_id),
            timezone = VALUES(timezone),
            settings = VALUES(settings),
            updated_at = VALUES(updated_at)
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM analytics_sites WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Site
    {
        $row = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByTrackingId(string $trackingId): ?Site
    {
        $row = $this->connection->query(self::SQL_FIND_BY_TRACKING_ID, ['tracking_id' => $trackingId])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findByDomain(string $domain): ?Site
    {
        $row = $this->connection->query(self::SQL_FIND_BY_DOMAIN, ['domain' => $domain])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findAll(): array
    {
        return $this->connection->query(self::SQL_FIND_ALL)->map(self::hydrate(...));
    }

    public function save(Site $site): void
    {
        $sql = $this->connection->driver() === Driver::MySQL
            ? self::SQL_UPSERT_MYSQL
            : self::SQL_UPSERT;

        $settingsJson = $site->settings !== [] ? json_encode($site->settings, JSON_THROW_ON_ERROR) : null;

        $this->connection->execute($sql, [
            'id' => $site->id,
            'domain' => $site->domain,
            'name' => $site->name,
            'tracking_id' => $site->trackingId,
            'timezone' => $site->timezone,
            'settings' => $settingsJson,
            'created_at' => $site->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $site->updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    private static function hydrate(Row $row): Site
    {
        $settingsRaw = $row->getNullableString('settings');
        /** @var array<string, mixed> $settings */
        $settings = $settingsRaw !== null ? json_decode($settingsRaw, true, 512, JSON_THROW_ON_ERROR) : [];

        return new Site(
            id: $row->getString('id'),
            domain: $row->getString('domain'),
            name: $row->getString('name'),
            trackingId: $row->getString('tracking_id'),
            timezone: $row->getString('timezone'),
            settings: $settings,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
