<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Releases\Release;
use Pulsar\Extension\Releases\ReleasePlatform;
use Pulsar\Extension\Releases\ReleaseRepositoryInterface;

use function ceil;
use function implode;
use function max;
use function min;

/**
 * Database-backed release repository using portable SQL (UpsertBuilder).
 */
#[Internal(reason: 'Raw-DB repository — use ReleaseRepositoryInterface for public API')]
final readonly class DbReleaseRepository implements ReleaseRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT *
        FROM releases
        WHERE id = :id
        SQL;

    private const string SQL_FIND_LATEST_STABLE = <<<'SQL'
        SELECT *
        FROM releases
        WHERE platform = :platform
            AND is_stable = :is_stable
        ORDER BY release_date DESC
        LIMIT 1
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'version', 'platform', 'release_date', 'release_notes',
        'minimum_os_version', 'download_url', 'is_beta', 'is_stable',
        'created_at',
    ];

    private const array UPSERT_UPDATE = [
        'version', 'platform', 'release_date', 'release_notes',
        'minimum_os_version', 'download_url', 'is_beta', 'is_stable',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(Release $release): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'releases',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $release->id,
            'version' => $release->version,
            'platform' => $release->platform->value,
            'release_date' => $release->releaseDate->format('c'),
            'release_notes' => $release->releaseNotes,
            'minimum_os_version' => $release->minimumOsVersion,
            'download_url' => $release->downloadUrl,
            'is_beta' => $release->isBeta ? 1 : 0,
            'is_stable' => $release->isStable ? 1 : 0,
            'created_at' => $release->createdAt->format('c'),
        ]);
    }

    public function findById(string $id): ?Release
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findLatestStable(ReleasePlatform $platform): ?Release
    {
        $result = $this->connection->query(self::SQL_FIND_LATEST_STABLE, [
            'platform' => $platform->value,
            'is_stable' => 1,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * @return PaginationResult<Release>
     */
    public function findAll(
        int $page,
        int $perPage,
        ?ReleasePlatform $platform = null,
        ?bool $includeBeta = null,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        if ($platform !== null) {
            $where[] = 'platform = :platform';
            $params['platform'] = $platform->value;
        }

        if ($includeBeta === false) {
            $where[] = 'is_beta = :is_beta';
            $params['is_beta'] = 0;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) AS total FROM releases {$whereClause}";
        $countResult = $this->connection->query($countSql, $params);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql = "SELECT * FROM releases {$whereClause} ORDER BY release_date DESC LIMIT :limit OFFSET :offset";
        $dataResult = $this->connection->query($selectSql, [
            ...$params,
            'limit' => $perPage,
            'offset' => $offset,
        ]);

        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    private static function hydrate(Row $row): Release
    {
        return new Release(
            id: $row->getString('id'),
            version: $row->getString('version'),
            platform: ReleasePlatform::from($row->getString('platform')),
            releaseDate: new DateTimeImmutable($row->getString('release_date')),
            releaseNotes: $row->getString('release_notes'),
            minimumOsVersion: $row->getString('minimum_os_version'),
            downloadUrl: $row->getNullableString('download_url'),
            isBeta: $row->getBool('is_beta'),
            isStable: $row->getBool('is_stable'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
