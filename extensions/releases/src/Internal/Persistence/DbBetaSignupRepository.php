<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Internal\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Releases\BetaSignup;
use Pulsar\Extension\Releases\BetaSignupRepositoryInterface;
use Pulsar\Extension\Releases\DeviceType;

use function ceil;
use function is_array;
use function json_decode;
use function json_encode;
use function max;
use function min;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed beta signup repository using portable SQL (UpsertBuilder).
 */
#[Internal(reason: 'Raw-DB repository — use BetaSignupRepositoryInterface for public API')]
final readonly class DbBetaSignupRepository implements BetaSignupRepositoryInterface
{
    private const string SQL_FIND_BY_EMAIL = <<<'SQL'
        SELECT *
        FROM beta_signups
        WHERE email = :email
        SQL;

    private const string SQL_COUNT_ALL = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM beta_signups
        SQL;

    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT *
        FROM beta_signups
        ORDER BY signed_up_at DESC
        SQL;

    private const string SQL_COUNT_BY_EMAIL_TODAY = <<<'SQL'
        SELECT COUNT(*) AS total
        FROM beta_signups
        WHERE email = :email
            AND signed_up_at >= :today_start
            AND signed_up_at < :tomorrow_start
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'email', 'device_type', 'camera_brands',
        'signed_up_at', 'invited_at', 'invite_token_hash',
    ];

    private const array UPSERT_UPDATE = [
        'device_type', 'camera_brands', 'invited_at', 'invite_token_hash',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function save(BetaSignup $signup): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'beta_signups',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $signup->id,
            'email' => $signup->email,
            'device_type' => $signup->deviceType->value,
            'camera_brands' => json_encode($signup->cameraBrands, JSON_THROW_ON_ERROR),
            'signed_up_at' => $signup->signedUpAt->format('c'),
            'invited_at' => $signup->invitedAt?->format('c'),
            'invite_token_hash' => $signup->inviteTokenHash,
        ]);
    }

    public function findByEmail(string $email): ?BetaSignup
    {
        $result = $this->connection->query(self::SQL_FIND_BY_EMAIL, ['email' => $email]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    /**
     * @return PaginationResult<BetaSignup>
     */
    public function findAll(int $page, int $perPage): PaginationResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT_ALL, []);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $selectSql = self::SQL_FIND_ALL . ' LIMIT :limit OFFSET :offset';
        $dataResult = $this->connection->query($selectSql, [
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

    public function countByEmailToday(string $email): int
    {
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $tomorrow = $today->modify('+1 day');

        $result = $this->connection->query(self::SQL_COUNT_BY_EMAIL_TODAY, [
            'email' => $email,
            'today_start' => $today->format('c'),
            'tomorrow_start' => $tomorrow->format('c'),
        ]);

        return $result->first()?->getInt('total') ?? 0;
    }

    private static function hydrate(Row $row): BetaSignup
    {
        $brandsJson = $row->getString('camera_brands');
        $decoded = json_decode($brandsJson, true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> $cameraBrands */
        $cameraBrands = is_array($decoded) ? $decoded : [];

        $invitedAtStr = $row->getNullableString('invited_at');

        return new BetaSignup(
            id: $row->getString('id'),
            email: $row->getString('email'),
            deviceType: DeviceType::from($row->getString('device_type')),
            cameraBrands: $cameraBrands,
            signedUpAt: new DateTimeImmutable($row->getString('signed_up_at')),
            invitedAt: $invitedAtStr !== null ? new DateTimeImmutable($invitedAtStr) : null,
            inviteTokenHash: $row->getNullableString('invite_token_hash'),
        );
    }
}
