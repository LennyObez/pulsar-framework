<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_map;
use function is_string;
use function sprintf;

/**
 * Admin controller for product catalog management.
 *
 * All actions require CMS commerce permissions checked via GateInterface.
 * State-changing operations require CSRF token validation.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class ProductController
{
    public function __construct(
        private ProductRepositoryInterface $products,
        private GateInterface $gate,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.view');

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($params['per_page'] ?? 20)));

        /** @var array<string, mixed> $filters */
        $filters = [];

        if (is_string($params['status'] ?? null) && $params['status'] !== '') {
            $filters['status'] = $params['status'];
        }

        if (is_string($params['type'] ?? null) && $params['type'] !== '') {
            $filters['digital'] = $params['type'] === 'digital';
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        if ($tenantId !== null) {
            $filters['tenantId'] = $tenantId;
        }

        $products = $this->products->listProducts($filters, $page, $perPage);

        return Response::json([
            'data' => array_map(static fn(Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'status' => $p->status->value,
                'price_amount' => $p->priceAmount,
                'price_currency' => $p->priceCurrency,
                'stock_quantity' => $p->stockQuantity,
                'digital' => $p->digital,
                'created_at' => $p->createdAt->format('c'),
                'updated_at' => $p->updatedAt->format('c'),
            ], $products),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function create(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.create');

        return Response::json([
            'product' => null,
            'statuses' => array_map(
                static fn(ProductStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                ProductStatus::cases(),
            ),
        ]);
    }

    public function store(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.create');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $sku = (string) ($body['sku'] ?? '');
        $priceAmount = (int) ($body['price_amount'] ?? 0);
        $priceCurrency = (string) ($body['price_currency'] ?? '');

        if ($sku === '' || $priceCurrency === '') {
            return Response::json(['error' => 'SKU and price currency are required'], 400);
        }

        if ($priceAmount < 0) {
            return Response::json(['error' => 'Price amount must be non-negative'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $product = Product::create(
            id: $this->generateUuidV7(),
            sku: $sku,
            priceAmount: $priceAmount,
            priceCurrency: $priceCurrency,
            tenantId: $tenantId,
            taxCategory: is_string($body['tax_category'] ?? null) ? $body['tax_category'] : null,
            stockQuantity: max(0, (int) ($body['stock_quantity'] ?? 0)),
            digital: (bool) ($body['digital'] ?? false),
            contentId: is_string($body['content_id'] ?? null) ? $body['content_id'] : null,
        );

        $this->products->save($product);

        return Response::json([
            'id' => $product->id,
            'status' => $product->status->value,
        ], 201);
    }

    public function edit(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.edit');

        $product = $this->products->findById($id);

        if ($product === null) {
            return Response::json(['error' => 'Product not found'], 404);
        }

        return Response::json([
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku,
                'status' => $product->status->value,
                'price_amount' => $product->priceAmount,
                'price_currency' => $product->priceCurrency,
                'tax_category' => $product->taxCategory,
                'stock_quantity' => $product->stockQuantity,
                'digital' => $product->digital,
                'content_id' => $product->contentId,
                'created_at' => $product->createdAt->format('c'),
                'updated_at' => $product->updatedAt->format('c'),
            ],
            'statuses' => array_map(
                static fn(ProductStatus $s) => ['value' => $s->value, 'label' => $s->label()],
                ProductStatus::cases(),
            ),
        ]);
    }

    public function update(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.edit');

        $product = $this->products->findById($id);

        if ($product === null) {
            return Response::json(['error' => 'Product not found'], 404);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $status = ProductStatus::tryFrom((string) ($body['status'] ?? $product->status->value));

        if ($status === null) {
            return Response::json(['error' => 'Invalid product status'], 400);
        }

        if ($status !== $product->status && !$product->status->canTransitionTo($status)) {
            return Response::json([
                'error' => "Cannot transition from '{$product->status->value}' to '{$status->value}'",
            ], 422);
        }

        $updated = new Product(
            id: $product->id,
            tenantId: $product->tenantId,
            sku: is_string($body['sku'] ?? null) && $body['sku'] !== '' ? $body['sku'] : $product->sku,
            status: $status,
            priceAmount: isset($body['price_amount']) ? max(0, (int) $body['price_amount']) : $product->priceAmount,
            priceCurrency: is_string($body['price_currency'] ?? null) && $body['price_currency'] !== ''
                ? $body['price_currency']
                : $product->priceCurrency,
            taxCategory: is_string($body['tax_category'] ?? null) ? $body['tax_category'] : $product->taxCategory,
            stockQuantity: isset($body['stock_quantity']) ? max(0, (int) $body['stock_quantity']) : $product->stockQuantity,
            digital: $product->digital,
            contentId: is_string($body['content_id'] ?? null) ? $body['content_id'] : $product->contentId,
            createdAt: $product->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        $this->products->save($updated);

        return Response::json(['id' => $id, 'status' => 'updated']);
    }

    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.commerce.products.delete');
        $this->requireStepUp($request);

        $product = $this->products->findById($id);

        if ($product === null) {
            return Response::json(['error' => 'Product not found'], 404);
        }

        $updated = new Product(
            id: $product->id,
            tenantId: $product->tenantId,
            sku: $product->sku,
            status: ProductStatus::Archived,
            priceAmount: $product->priceAmount,
            priceCurrency: $product->priceCurrency,
            taxCategory: $product->taxCategory,
            stockQuantity: $product->stockQuantity,
            digital: $product->digital,
            contentId: $product->contentId,
            createdAt: $product->createdAt,
            updatedAt: new DateTimeImmutable(),
        );

        $this->products->save($updated);

        return Response::json(['id' => $id, 'status' => 'deleted']);
    }

    private function requireStepUp(ServerRequestInterface $request): void
    {
        $stepUp = $request->getAttribute('step_up_verified', false);

        if ($stepUp !== true) {
            throw new RuntimeException('Step-up authentication required for this action');
        }
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
