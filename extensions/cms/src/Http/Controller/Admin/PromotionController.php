<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\Coupon;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Admin controller for promotion and coupon management.
 *
 * All actions require CMS commerce permissions checked via GateInterface.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class PromotionController
{
    public function __construct(
        private PromotionRepositoryInterface $promotions,
        private CouponRepositoryInterface $coupons,
        private GateInterface $gate,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.view');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $promotions = $this->promotions->findActive($tenantId);

        return Response::json([
            'data' => array_map(static fn(Promotion $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type->value,
                'value' => $p->value,
                'current_uses' => $p->currentUses,
                'max_uses' => $p->maxUses,
                'is_active' => $p->isActive,
                'starts_at' => $p->startsAt?->format('c'),
                'expires_at' => $p->expiresAt?->format('c'),
            ], $promotions),
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.create');

        return Response::json([
            'promotion' => null,
            'types' => array_map(
                static fn(PromotionType $t) => ['value' => $t->value, 'label' => $t->label()],
                PromotionType::cases(),
            ),
        ]);
    }

    public function store(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $name = (string) ($body['name'] ?? '');
        $typeStr = (string) ($body['type'] ?? '');

        if ($name === '') {
            return Response::json(['error' => 'Promotion name is required'], 400);
        }

        $type = PromotionType::tryFrom($typeStr);

        if ($type === null) {
            return Response::json(['error' => 'Invalid promotion type'], 400);
        }

        $value = (int) ($body['value'] ?? 0);

        if ($value <= 0) {
            return Response::json(['error' => 'Promotion value must be positive'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $startsAt = is_string($body['starts_at'] ?? null) && $body['starts_at'] !== ''
            ? new DateTimeImmutable($body['starts_at'])
            : null;

        $expiresAt = is_string($body['expires_at'] ?? null) && $body['expires_at'] !== ''
            ? new DateTimeImmutable($body['expires_at'])
            : null;

        /** @var list<string> $productIds */
        $productIds = is_array($body['applicable_product_ids'] ?? null)
            ? $body['applicable_product_ids']
            : [];

        /** @var list<string> $categoryIds */
        $categoryIds = is_array($body['applicable_category_ids'] ?? null)
            ? $body['applicable_category_ids']
            : [];

        $promotionId = $this->generateUuidV7();

        $promotion = new Promotion(
            id: $promotionId,
            tenantId: $tenantId,
            name: $name,
            type: $type,
            value: $value,
            minOrderAmount: isset($body['min_order_amount']) ? (int) $body['min_order_amount'] : null,
            maxUses: isset($body['max_uses']) ? (int) $body['max_uses'] : null,
            maxUsesPerCustomer: isset($body['max_uses_per_customer']) ? (int) $body['max_uses_per_customer'] : null,
            currentUses: 0,
            applicableProductIds: $productIds,
            applicableCategoryIds: $categoryIds,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            isActive: true,
        );

        $this->promotions->save($promotion);

        // Create coupons if provided
        /** @var list<array{code: string, single_use?: bool}> $couponData */
        $couponData = is_array($body['coupons'] ?? null) ? $body['coupons'] : [];

        foreach ($couponData as $cd) {
            if (!is_array($cd) || !is_string($cd['code'] ?? null) || $cd['code'] === '') {
                continue;
            }

            $coupon = new Coupon(
                id: $this->generateUuidV7(),
                promotionId: $promotionId,
                code: $cd['code'],
                isSingleUse: (bool) ($cd['single_use'] ?? false),
                usedAt: null,
                usedBy: null,
            );

            $this->coupons->save($coupon);
        }

        return Response::json([
            'id' => $promotionId,
            'status' => 'created',
        ], 201);
    }

    public function edit(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.edit');

        $promotion = $this->promotions->findById($id);

        if ($promotion === null) {
            return Response::json(['error' => 'Promotion not found'], 404);
        }

        return Response::json([
            'promotion' => [
                'id' => $promotion->id,
                'name' => $promotion->name,
                'type' => $promotion->type->value,
                'value' => $promotion->value,
                'min_order_amount' => $promotion->minOrderAmount,
                'max_uses' => $promotion->maxUses,
                'max_uses_per_customer' => $promotion->maxUsesPerCustomer,
                'current_uses' => $promotion->currentUses,
                'applicable_product_ids' => $promotion->applicableProductIds,
                'applicable_category_ids' => $promotion->applicableCategoryIds,
                'starts_at' => $promotion->startsAt?->format('c'),
                'expires_at' => $promotion->expiresAt?->format('c'),
                'is_active' => $promotion->isActive,
            ],
            'types' => array_map(
                static fn(PromotionType $t) => ['value' => $t->value, 'label' => $t->label()],
                PromotionType::cases(),
            ),
        ]);
    }

    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.edit');

        $promotion = $this->promotions->findById($id);

        if ($promotion === null) {
            return Response::json(['error' => 'Promotion not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $name = is_string($body['name'] ?? null) && $body['name'] !== '' ? $body['name'] : $promotion->name;

        $type = $promotion->type;

        if (is_string($body['type'] ?? null) && $body['type'] !== '') {
            $parsed = PromotionType::tryFrom($body['type']);

            if ($parsed === null) {
                return Response::json(['error' => 'Invalid promotion type'], 400);
            }

            $type = $parsed;
        }

        $startsAt = $promotion->startsAt;

        if (is_string($body['starts_at'] ?? null) && $body['starts_at'] !== '') {
            $startsAt = new DateTimeImmutable($body['starts_at']);
        }

        $expiresAt = $promotion->expiresAt;

        if (is_string($body['expires_at'] ?? null) && $body['expires_at'] !== '') {
            $expiresAt = new DateTimeImmutable($body['expires_at']);
        }

        $updated = new Promotion(
            id: $promotion->id,
            tenantId: $promotion->tenantId,
            name: $name,
            type: $type,
            value: isset($body['value']) ? (int) $body['value'] : $promotion->value,
            minOrderAmount: isset($body['min_order_amount']) ? (int) $body['min_order_amount'] : $promotion->minOrderAmount,
            maxUses: isset($body['max_uses']) ? (int) $body['max_uses'] : $promotion->maxUses,
            maxUsesPerCustomer: isset($body['max_uses_per_customer']) ? (int) $body['max_uses_per_customer'] : $promotion->maxUsesPerCustomer,
            currentUses: $promotion->currentUses,
            applicableProductIds: is_array($body['applicable_product_ids'] ?? null)
                ? $body['applicable_product_ids']
                : $promotion->applicableProductIds,
            applicableCategoryIds: is_array($body['applicable_category_ids'] ?? null)
                ? $body['applicable_category_ids']
                : $promotion->applicableCategoryIds,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            isActive: isset($body['is_active']) ? (bool) $body['is_active'] : $promotion->isActive,
        );

        $this->promotions->save($updated);

        return Response::json(['id' => $id, 'status' => 'updated']);
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.delete');

        $promotion = $this->promotions->findById($id);

        if ($promotion === null) {
            return Response::json(['error' => 'Promotion not found'], 404);
        }

        // Deactivate rather than hard-delete for audit trail
        $deactivated = new Promotion(
            id: $promotion->id,
            tenantId: $promotion->tenantId,
            name: $promotion->name,
            type: $promotion->type,
            value: $promotion->value,
            minOrderAmount: $promotion->minOrderAmount,
            maxUses: $promotion->maxUses,
            maxUsesPerCustomer: $promotion->maxUsesPerCustomer,
            currentUses: $promotion->currentUses,
            applicableProductIds: $promotion->applicableProductIds,
            applicableCategoryIds: $promotion->applicableCategoryIds,
            startsAt: $promotion->startsAt,
            expiresAt: $promotion->expiresAt,
            isActive: false,
        );

        $this->promotions->save($deactivated);

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }

    private function generateUuidV7(): string
    {
        $time = (int) (microtime(true) * 1000);
        $hex = str_pad(dechex($time), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(8));

        return sprintf(
            '%s-%s-7%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($random, 0, 3),
            dechex(0x80 | (hexdec(substr($random, 3, 2)) & 0x3F)) . substr($random, 5, 2),
            substr($random, 7, 12),
        );
    }
}
