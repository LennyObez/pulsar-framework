<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionType;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Database-backed promotion repository with atomic usage counting.
 */
#[Internal(reason: 'Use PromotionRepositoryInterface for public API')]
final readonly class DbPromotionRepository implements PromotionRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_promotions WHERE id = :id
        SQL;

    private const string SQL_FIND_ACTIVE = <<<'SQL'
        SELECT * FROM cms_promotions
        WHERE is_active = 1
            AND (starts_at IS NULL OR starts_at <= :now)
            AND (expires_at IS NULL OR expires_at >= :now)
        SQL;

    private const string SQL_FIND_BY_COUPON_CODE = <<<'SQL'
        SELECT p.* FROM cms_promotions p
        INNER JOIN cms_coupons c ON c.promotion_id = p.id
        WHERE LOWER(c.code) = LOWER(:code)
        LIMIT 1
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO cms_promotions (
            id, tenant_id, name, type, value, min_order_amount,
            max_uses, max_uses_per_customer, current_uses,
            applicable_product_ids, applicable_category_ids,
            starts_at, expires_at, is_active
        ) VALUES (
            :id, :tenant_id, :name, :type, :value, :min_order_amount,
            :max_uses, :max_uses_per_customer, :current_uses,
            :applicable_product_ids, :applicable_category_ids,
            :starts_at, :expires_at, :is_active
        )
        ON CONFLICT (id) DO UPDATE SET
            name = EXCLUDED.name,
            type = EXCLUDED.type,
            value = EXCLUDED.value,
            min_order_amount = EXCLUDED.min_order_amount,
            max_uses = EXCLUDED.max_uses,
            max_uses_per_customer = EXCLUDED.max_uses_per_customer,
            current_uses = EXCLUDED.current_uses,
            applicable_product_ids = EXCLUDED.applicable_product_ids,
            applicable_category_ids = EXCLUDED.applicable_category_ids,
            starts_at = EXCLUDED.starts_at,
            expires_at = EXCLUDED.expires_at,
            is_active = EXCLUDED.is_active
        SQL;

    private const string SQL_INCREMENT_USAGE = <<<'SQL'
        UPDATE cms_promotions SET current_uses = current_uses + 1 WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $db,
        private ?string $tenantId = null,
    ) {}

    public function findById(string $id): ?Promotion
    {
        $row = $this->db->query(self::SQL_FIND_BY_ID, ['id' => $id])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findActive(?string $tenantId = null): array
    {
        $sql = self::SQL_FIND_ACTIVE;
        $bindings = ['now' => new DateTimeImmutable()->format('c')];
        $effectiveTenantId = $tenantId ?? $this->tenantId;

        if ($effectiveTenantId !== null) {
            $sql .= ' AND tenant_id = :tenant_id';
            $bindings['tenant_id'] = $effectiveTenantId;
        }

        return $this->db->query($sql, $bindings)->map(self::hydrate(...));
    }

    public function findByCouponCode(string $code): ?Promotion
    {
        $row = $this->db->query(self::SQL_FIND_BY_COUPON_CODE, ['code' => $code])->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function save(Promotion $promotion): void
    {
        $this->db->execute(self::SQL_UPSERT, [
            'id' => $promotion->id,
            'tenant_id' => $promotion->tenantId,
            'name' => $promotion->name,
            'type' => $promotion->type->value,
            'value' => $promotion->value,
            'min_order_amount' => $promotion->minOrderAmount,
            'max_uses' => $promotion->maxUses,
            'max_uses_per_customer' => $promotion->maxUsesPerCustomer,
            'current_uses' => $promotion->currentUses,
            'applicable_product_ids' => json_encode($promotion->applicableProductIds, JSON_THROW_ON_ERROR),
            'applicable_category_ids' => json_encode($promotion->applicableCategoryIds, JSON_THROW_ON_ERROR),
            'starts_at' => $promotion->startsAt?->format('c'),
            'expires_at' => $promotion->expiresAt?->format('c'),
            'is_active' => $promotion->isActive ? 1 : 0,
        ]);
    }

    public function incrementUsage(string $promotionId): void
    {
        $this->db->execute(self::SQL_INCREMENT_USAGE, ['id' => $promotionId]);
    }

    private static function hydrate(Row $row): Promotion
    {
        /** @var list<string> $applicableProductIds */
        $applicableProductIds = json_decode($row->getString('applicable_product_ids'), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<string> $applicableCategoryIds */
        $applicableCategoryIds = json_decode($row->getString('applicable_category_ids'), true, 512, JSON_THROW_ON_ERROR);

        return new Promotion(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            name: $row->getString('name'),
            type: PromotionType::from($row->getString('type')),
            value: $row->getInt('value'),
            minOrderAmount: $row->getNullableString('min_order_amount') !== null ? $row->getInt('min_order_amount') : null,
            maxUses: $row->getNullableString('max_uses') !== null ? $row->getInt('max_uses') : null,
            maxUsesPerCustomer: $row->getNullableString('max_uses_per_customer') !== null ? $row->getInt('max_uses_per_customer') : null,
            currentUses: $row->getInt('current_uses'),
            applicableProductIds: $applicableProductIds,
            applicableCategoryIds: $applicableCategoryIds,
            startsAt: self::toDateTime($row->getNullableString('starts_at')),
            expiresAt: self::toDateTime($row->getNullableString('expires_at')),
            isActive: (bool) $row->getInt('is_active'),
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
