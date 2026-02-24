<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Coupon;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;

/**
 * Database-backed coupon repository with case-insensitive lookup.
 */
#[Internal(reason: 'Use CouponRepositoryInterface for public API')]
final readonly class DbCouponRepository implements CouponRepositoryInterface
{
    private const string SQL_FIND_BY_CODE = <<<'SQL'
        SELECT * FROM cms_coupons WHERE LOWER(code) = LOWER(:code) LIMIT 1
        SQL;

    private const array UPSERT_COLUMNS = ['id', 'promotion_id', 'code', 'is_single_use', 'used_at', 'used_by'];
    private const array UPSERT_UPDATE = ['used_at', 'used_by'];

    private const string SQL_MARK_USED = <<<'SQL'
        UPDATE cms_coupons SET used_at = :now, used_by = :customer_id WHERE id = :id
        SQL;

    private const string SQL_COUNT_CUSTOMER_USAGE = <<<'SQL'
        SELECT COUNT(*) AS cnt FROM cms_coupon_usages
        WHERE promotion_id = :promotion_id AND customer_id = :customer_id
        SQL;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function findByCode(string $code): ?Coupon
    {
        $row = $this->db->query(self::SQL_FIND_BY_CODE, ['code' => $code])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function save(Coupon $coupon): void
    {
        $sql = UpsertBuilder::compile(
            $this->db->driver(),
            'cms_coupons',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->db->execute($sql, [
            'id' => $coupon->id,
            'promotion_id' => $coupon->promotionId,
            'code' => $coupon->code,
            'is_single_use' => $coupon->isSingleUse ? 1 : 0,
            'used_at' => $coupon->usedAt?->format('c'),
            'used_by' => $coupon->usedBy,
        ]);
    }

    public function markUsed(string $couponId, string $customerId): void
    {
        $this->db->execute(self::SQL_MARK_USED, [
            'id' => $couponId,
            'customer_id' => $customerId,
            'now' => new DateTimeImmutable()->format('c'),
        ]);
    }

    public function countCustomerUsage(string $promotionId, string $customerId): int
    {
        return $this->db->query(self::SQL_COUNT_CUSTOMER_USAGE, [
            'promotion_id' => $promotionId,
            'customer_id' => $customerId,
        ])->first()?->getInt('cnt') ?? 0;
    }

    private static function hydrate(Row $row): Coupon
    {
        return new Coupon(
            id: $row->getString('id'),
            promotionId: $row->getString('promotion_id'),
            code: $row->getString('code'),
            isSingleUse: (bool) $row->getInt('is_single_use'),
            usedAt: self::toDateTime($row->getNullableString('used_at')),
            usedBy: $row->getNullableString('used_by'),
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
