<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Exception\AuthenticationException;
use Pulsar\Auth\Exception\AuthorizationException;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Commerce\DigitalAsset;
use Pulsar\Extension\Cms\Commerce\DigitalAssetRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Product;
use Pulsar\Extension\Cms\Commerce\ProductRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\ProductStatus;
use Pulsar\Extension\Cms\Http\Controller\Admin\DigitalAssetController;
use Pulsar\Extension\Cms\Media\MediaDiskInterface;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DigitalAssetController::class)]
final class DigitalAssetControllerTest extends TestCase
{
    #[Test]
    public function index_returns_assets_for_product(): void
    {
        $product = $this->createProduct('prod-1', digital: true);
        $asset = $this->createDigitalAsset('asset-1', 'prod-1');

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $assets = $this->createStub(DigitalAssetRepositoryInterface::class);
        $assets->method('findByProduct')->willReturn([$asset]);

        $controller = $this->createController(assets: $assets, products: $products);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request, 'prod-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $data */
        $data = $body['data'];
        self::assertCount(1, $data);
        self::assertSame('asset-1', $data[0]['id']);
        self::assertSame('download.zip', $data[0]['file_name']);
        self::assertSame(1024, $data[0]['file_size']);
    }

    #[Test]
    public function index_returns_404_when_product_not_found(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn(null);

        $controller = $this->createController(products: $products);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function upload_returns_400_when_no_file(): void
    {
        $product = $this->createProduct('prod-1', digital: true);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $controller = $this->createController(products: $products);
        $request = $this->createAuthenticatedRequest(uploadedFiles: []);

        $response = $controller->upload($request, 'prod-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function upload_returns_404_when_product_not_found(): void
    {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn(null);

        $controller = $this->createController(products: $products);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->upload($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function upload_returns_422_when_product_not_digital(): void
    {
        $product = $this->createProduct('prod-1', digital: false);

        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($product);

        $controller = $this->createController(products: $products);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->upload($request, 'prod-1');

        self::assertSame(422, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('not a digital product', $body['error']);
    }

    #[Test]
    public function delete_returns_success(): void
    {
        $asset = $this->createDigitalAsset('asset-1', 'prod-1');

        $assets = $this->createStub(DigitalAssetRepositoryInterface::class);
        $assets->method('findByProduct')->willReturn([$asset]);

        $disk = $this->createStub(MediaDiskInterface::class);

        $controller = $this->createController(assets: $assets, disk: $disk);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'prod-1', 'asset-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
        self::assertSame('asset-1', $body['id']);
    }

    #[Test]
    public function delete_returns_404_when_asset_not_found(): void
    {
        $assets = $this->createStub(DigitalAssetRepositoryInterface::class);
        $assets->method('findByProduct')->willReturn([]);

        $controller = $this->createController(assets: $assets);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'prod-1', 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest(), 'prod-1');
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request, 'prod-1');
    }

    private function createController(
        ?DigitalAssetRepositoryInterface $assets = null,
        ?ProductRepositoryInterface $products = null,
        ?MediaDiskInterface $disk = null,
        ?GateInterface $gate = null,
    ): DigitalAssetController {
        return new DigitalAssetController(
            assets: $assets ?? $this->createStub(DigitalAssetRepositoryInterface::class),
            products: $products ?? $this->createStub(ProductRepositoryInterface::class),
            disk: $disk ?? $this->createStub(MediaDiskInterface::class),
            gate: $gate,
        );
    }

    private function createProduct(string $id, bool $digital): Product
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Product(
            id: $id,
            tenantId: null,
            sku: 'SKU-' . $id,
            status: ProductStatus::Active,
            priceAmount: 1999,
            priceCurrency: 'USD',
            taxCategory: null,
            stockQuantity: 10,
            digital: $digital,
            contentId: null,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function createDigitalAsset(string $id, string $productId): DigitalAsset
    {
        return new DigitalAsset(
            id: $id,
            productId: $productId,
            fileStoragePath: "digital-assets/$productId/$id/download.zip",
            fileHash: 'abc123hash',
            fileName: 'download.zip',
            fileSize: 1024,
            maxDownloads: 5,
        );
    }

    /**
     * @param array<string, UploadedFileInterface>|null $uploadedFiles
     */
    private function createAuthenticatedRequest(
        ?array $uploadedFiles = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/digital-assets');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                default => $default,
            },
        );

        if ($uploadedFiles !== null) {
            $request->method('getUploadedFiles')->willReturn($uploadedFiles);
        }

        return $request;
    }

    private function createUnauthenticatedRequest(): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/digital-assets');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
