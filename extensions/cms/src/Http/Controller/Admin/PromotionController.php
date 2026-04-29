<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Commerce\Coupon;
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Support\UuidGenerator;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;

/**
 * Admin controller for promotion and coupon management.
 *
 * All actions require CMS commerce permissions checked via GateInterface.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class PromotionController extends AbstractAdminController
{
    public function __construct(
        private PromotionRepositoryInterface $promotions,
        private CouponRepositoryInterface $coupons,
        ?GateInterface $gate = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.promotions.view');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $promotions = $this->promotions->findActive($tenantId);

        return $this->respondWithView($request, 'admin.promotions.index', [
            'promotions' => array_map(static fn(Promotion $p) => [
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

        return $this->respondWithView($request, 'admin.promotions.form', [
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

        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) ? $rawName : '';
        /** @var mixed $rawTypeStr */
        $rawTypeStr = $body['type'] ?? null;
        $typeStr = is_string($rawTypeStr) ? $rawTypeStr : '';

        if ($name === '') {
            return Response::json(['error' => 'Promotion name is required'], 400);
        }

        $type = PromotionType::tryFrom($typeStr);

        if ($type === null) {
            return Response::json(['error' => 'Invalid promotion type'], 400);
        }

        /** @var mixed $rawValue */
        $rawValue = $body['value'] ?? null;
        $value = is_int($rawValue) ? $rawValue : 0;

        if ($value <= 0) {
            return Response::json(['error' => 'Promotion value must be positive'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        /** @var mixed $rawStartsAt */
        $rawStartsAt = $body['starts_at'] ?? null;
        $startsAt = is_string($rawStartsAt) && $rawStartsAt !== ''
            ? new DateTimeImmutable($rawStartsAt)
            : null;

        /** @var mixed $rawExpiresAt */
        $rawExpiresAt = $body['expires_at'] ?? null;
        $expiresAt = is_string($rawExpiresAt) && $rawExpiresAt !== ''
            ? new DateTimeImmutable($rawExpiresAt)
            : null;

        /** @var list<string> $productIds */
        $productIds = is_array($body['applicable_product_ids'] ?? null)
            ? $body['applicable_product_ids']
            : [];

        /** @var list<string> $categoryIds */
        $categoryIds = is_array($body['applicable_category_ids'] ?? null)
            ? $body['applicable_category_ids']
            : [];

        $promotionId = UuidGenerator::v7();

        $promotion = new Promotion(
            id: $promotionId,
            tenantId: $tenantId,
            name: $name,
            type: $type,
            value: $value,
            minOrderAmount: isset($body['min_order_amount']) ? (is_int($body['min_order_amount']) ? $body['min_order_amount'] : 0) : null,
            maxUses: isset($body['max_uses']) ? (is_int($body['max_uses']) ? $body['max_uses'] : 0) : null,
            maxUsesPerCustomer: isset($body['max_uses_per_customer']) ? (is_int($body['max_uses_per_customer']) ? $body['max_uses_per_customer'] : 0) : null,
            currentUses: 0,
            applicableProductIds: $productIds,
            applicableCategoryIds: $categoryIds,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            isActive: true,
        );

        $this->promotions->save($promotion);

        // Create coupons if provided
        /** @var list<mixed> $couponData */
        $couponData = is_array($body['coupons'] ?? null) ? $body['coupons'] : [];

        foreach ($couponData as $cd) {
            if (!is_array($cd)) {
                continue;
            }

            /** @var mixed $rawCode */
            $rawCode = $cd['code'] ?? null;

            if (!is_string($rawCode) || $rawCode === '') {
                continue;
            }

            /** @var mixed $rawSingleUse */
            $rawSingleUse = $cd['single_use'] ?? null;

            $coupon = new Coupon(
                id: UuidGenerator::v7(),
                promotionId: $promotionId,
                code: $rawCode,
                isSingleUse: is_bool($rawSingleUse) ? $rawSingleUse : false,
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

        return $this->respondWithView($request, 'admin.promotions.form', [
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

        /** @var mixed $rawName */
        $rawName = $body['name'] ?? null;
        $name = is_string($rawName) && $rawName !== '' ? $rawName : $promotion->name;

        $type = $promotion->type;

        /** @var mixed $rawType */
        $rawType = $body['type'] ?? null;
        if (is_string($rawType) && $rawType !== '') {
            $parsed = PromotionType::tryFrom($rawType);

            if ($parsed === null) {
                return Response::json(['error' => 'Invalid promotion type'], 400);
            }

            $type = $parsed;
        }

        $startsAt = $promotion->startsAt;

        /** @var mixed $rawStartsAt */
        $rawStartsAt = $body['starts_at'] ?? null;
        if (is_string($rawStartsAt) && $rawStartsAt !== '') {
            $startsAt = new DateTimeImmutable($rawStartsAt);
        }

        $expiresAt = $promotion->expiresAt;

        /** @var mixed $rawExpiresAt */
        $rawExpiresAt = $body['expires_at'] ?? null;
        if (is_string($rawExpiresAt) && $rawExpiresAt !== '') {
            $expiresAt = new DateTimeImmutable($rawExpiresAt);
        }

        $updated = new Promotion(
            id: $promotion->id,
            tenantId: $promotion->tenantId,
            name: $name,
            type: $type,
            value: isset($body['value']) ? (is_int($body['value']) ? $body['value'] : 0) : $promotion->value,
            minOrderAmount: isset($body['min_order_amount']) ? (is_int($body['min_order_amount']) ? $body['min_order_amount'] : 0) : $promotion->minOrderAmount,
            maxUses: isset($body['max_uses']) ? (is_int($body['max_uses']) ? $body['max_uses'] : 0) : $promotion->maxUses,
            maxUsesPerCustomer: isset($body['max_uses_per_customer']) ? (is_int($body['max_uses_per_customer']) ? $body['max_uses_per_customer'] : 0) : $promotion->maxUsesPerCustomer,
            currentUses: $promotion->currentUses,
            applicableProductIds: is_array($body['applicable_product_ids'] ?? null)
                ? array_values(array_filter($body['applicable_product_ids'], 'is_string'))
                : $promotion->applicableProductIds,
            applicableCategoryIds: is_array($body['applicable_category_ids'] ?? null)
                ? array_values(array_filter($body['applicable_category_ids'], 'is_string'))
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

}
