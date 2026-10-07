<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Http\Controller\Admin\ProductController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ProductController::class)]
final class ProductControllerTest extends TestCase
{
    #[Test]
    public function index_returns_product_list(): void
    {
        $product = $this->createProduct('prod-1');

        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('listProducts')->willReturn([$product]);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $products */
        $products = $body['products'];
        self::assertCount(1, $products);
        self::assertSame('prod-1', $products[0]['id']);
        self::assertSame('SKU-001', $products[0]['sku']);
        self::assertSame('active', $products[0]['status']);
    }

    #[Test]
    public function create_returns_form_data(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->create($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['product']);
        self::assertIsArray($body['statuses']);
    }

    #[Test]
    public function store_returns_201_with_valid_data(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'sku' => 'NEW-SKU',
            'price_amount' => 1999,
            'price_currency' => 'USD',
        ]);

        $response = $controller->store($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['id']);
        self::assertSame('draft', $body['status']);
    }

    #[Test]
    public function store_returns_400_when_sku_missing(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'sku' => '',
            'price_amount' => 1999,
            'price_currency' => 'USD',
        ]);

        $response = $controller->store($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function store_returns_400_when_price_negative(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'sku' => 'TEST-SKU',
            'price_amount' => -100,
            'price_currency' => 'USD',
        ]);

        $response = $controller->store($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function edit_returns_product_data(): void
    {
        $product = $this->createProduct('prod-1');

        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturn($product);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->edit($request, 'prod-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $productData */
        $productData = $body['product'];
        self::assertSame('prod-1', $productData['id']);
        self::assertSame('SKU-001', $productData['sku']);
    }

    #[Test]
    public function edit_returns_404_when_product_not_found(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->edit($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_success(): void
    {
        $product = $this->createProduct('prod-1');

        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturn($product);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'sku' => 'UPDATED-SKU',
            'price_amount' => 2999,
        ]);

        $response = $controller->update($request, 'prod-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_404_when_product_not_found(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function delete_returns_success(): void
    {
        $product = $this->createProduct('prod-1');

        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturn($product);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest(stepUp: true);

        $response = $controller->delete($request, 'prod-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_404_when_product_not_found(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $controller = new ProductController(products: $repo);
        $request = $this->createAuthenticatedRequest(stepUp: true);

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $controller = new ProductController(products: $repo);

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $repo = $this->createStub(ProductRepositoryInterface::class);
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = new ProductController(products: $repo, gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createProduct(string $id): Product
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Product(
            id: $id,
            tenantId: null,
            sku: 'SKU-001',
            status: ProductStatus::Active,
            priceAmount: 2999,
            priceCurrency: 'USD',
            taxCategory: null,
            stockQuantity: 50,
            digital: false,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        bool $stepUp = false,
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/products');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => $stepUp,
                default => $default,
            },
        );

        if ($parsedBody !== null) {
            $request->method('getParsedBody')->willReturn($parsedBody);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/products');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
