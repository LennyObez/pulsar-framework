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
use Pulsar\Extension\Cms\Commerce\CouponRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\Promotion;
use Pulsar\Extension\Cms\Commerce\PromotionRepositoryInterface;
use Pulsar\Extension\Cms\Commerce\PromotionType;
use Pulsar\Extension\Cms\Http\Controller\Admin\PromotionController;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(PromotionController::class)]
final class PromotionControllerTest extends TestCase
{
    #[Test]
    public function index_returns_active_promotions(): void
    {
        $promotion = $this->createPromotion('promo-1');

        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findActive')->willReturn([$promotion]);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var list<array<string, mixed>> $promotions */
        $promotions = $body['promotions'];
        self::assertCount(1, $promotions);
        self::assertSame('promo-1', $promotions[0]['id']);
        self::assertSame('Summer Sale', $promotions[0]['name']);
        self::assertSame('percentage_off', $promotions[0]['type']);
        self::assertTrue($promotions[0]['is_active']);
    }

    #[Test]
    public function create_returns_form_with_promotion_types(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest();

        $response = $controller->create($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['promotion']);
        self::assertIsArray($body['types']);
        self::assertNotEmpty($body['types']);
    }

    #[Test]
    public function store_returns_201_with_valid_data(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => 'New Promo',
            'type' => 'percentage_off',
            'value' => 15,
        ]);

        $response = $controller->store($request);

        self::assertSame(201, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['id']);
        self::assertSame('created', $body['status']);
    }

    #[Test]
    public function store_returns_400_when_name_empty(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => '',
            'type' => 'percentage_off',
            'value' => 10,
        ]);

        $response = $controller->store($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('name', $body['error']);
    }

    #[Test]
    public function store_returns_400_for_invalid_type(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => 'Test Promo',
            'type' => 'invalid_type',
            'value' => 10,
        ]);

        $response = $controller->store($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('type', $body['error']);
    }

    #[Test]
    public function store_returns_400_when_value_not_positive(): void
    {
        $controller = $this->createController();
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => 'Test Promo',
            'type' => 'fixed_amount_off',
            'value' => 0,
        ]);

        $response = $controller->store($request);

        self::assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($body['error']);
        self::assertStringContainsString('positive', $body['error']);
    }

    #[Test]
    public function edit_returns_promotion_data(): void
    {
        $promotion = $this->createPromotion('promo-1');

        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn($promotion);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->edit($request, 'promo-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $promotionData */
        $promotionData = $body['promotion'];
        self::assertSame('promo-1', $promotionData['id']);
        self::assertSame('Summer Sale', $promotionData['name']);
        self::assertIsArray($body['types']);
    }

    #[Test]
    public function edit_returns_404_when_not_found(): void
    {
        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn(null);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->edit($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_success(): void
    {
        $promotion = $this->createPromotion('promo-1');

        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn($promotion);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'name' => 'Updated Sale',
            'value' => 25,
        ]);

        $response = $controller->update($request, 'promo-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('updated', $body['status']);
    }

    #[Test]
    public function update_returns_404_when_not_found(): void
    {
        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn(null);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->update($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function update_returns_400_for_invalid_type(): void
    {
        $promotion = $this->createPromotion('promo-1');

        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn($promotion);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest(parsedBody: [
            'type' => 'nonexistent_type',
        ]);

        $response = $controller->update($request, 'promo-1');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function delete_deactivates_promotion(): void
    {
        $promotion = $this->createPromotion('promo-1');

        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn($promotion);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'promo-1');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('deleted', $body['status']);
    }

    #[Test]
    public function delete_returns_404_when_not_found(): void
    {
        $promoRepo = $this->createStub(PromotionRepositoryInterface::class);
        $promoRepo->method('findById')->willReturn(null);

        $controller = $this->createController(promoRepo: $promoRepo);
        $request = $this->createAuthenticatedRequest();

        $response = $controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function index_throws_when_unauthenticated(): void
    {
        $controller = $this->createController();

        $this->expectException(AuthenticationException::class);
        $controller->index($this->createUnauthenticatedRequest());
    }

    #[Test]
    public function index_throws_when_authorization_denied(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $controller = $this->createController(gate: $gate);
        $request = $this->createAuthenticatedRequest();

        $this->expectException(AuthorizationException::class);
        $controller->index($request);
    }

    private function createController(
        ?PromotionRepositoryInterface $promoRepo = null,
        ?GateInterface $gate = null,
    ): PromotionController {
        return new PromotionController(
            promotions: $promoRepo ?? $this->createStub(PromotionRepositoryInterface::class),
            coupons: $this->createStub(CouponRepositoryInterface::class),
            gate: $gate,
        );
    }

    private function createPromotion(string $id): Promotion
    {
        $now = new DateTimeImmutable('2026-03-10T12:00:00+00:00');

        return new Promotion(
            id: $id,
            tenantId: null,
            name: 'Summer Sale',
            type: PromotionType::PercentageOff,
            value: 20,
            minOrderAmount: 5000,
            maxUses: 100,
            maxUsesPerCustomer: 1,
            currentUses: 15,
            applicableProductIds: [],
            applicableCategoryIds: [],
            startsAt: $now,
            expiresAt: new DateTimeImmutable('2026-06-30T23:59:59+00:00'),
            isActive: true,
        );
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     */
    private function createAuthenticatedRequest(
        ?array $parsedBody = null,
    ): ServerRequestInterface {
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);
        $identity->method('id')->willReturn('admin-1');

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/admin/cms/promotions');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name, mixed $default = null): mixed => match ($name) {
                'identity' => $identity,
                'step_up_verified' => false,
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
        $uri->method('getPath')->willReturn('/admin/cms/promotions');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getUri')->willReturn($uri);
        $request->method('getAttribute')->willReturn(null);

        return $request;
    }
}
