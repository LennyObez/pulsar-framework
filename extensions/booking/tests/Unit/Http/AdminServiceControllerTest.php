<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Booking\Http\Controller\Admin\AdminServiceController;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;

#[CoversClass(AdminServiceController::class)]
final class AdminServiceControllerTest extends TestCase
{
    private ConnectionInterface&Stub $connection;
    private AdminServiceController $controller;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->controller = new AdminServiceController($this->connection);
    }

    #[Test]
    public function createServiceReturns400WhenNameEmpty(): void
    {
        $request = $this->buildRequest(Method::POST, '/admin/booking/services', ['name' => '']);
        $response = $this->controller->createService($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('error', $body);
    }

    #[Test]
    public function createServiceReturns201WithId(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $request = $this->buildRequest(Method::POST, '/admin/booking/services', [
            'name' => 'Haircut',
            'description' => 'Standard haircut',
            'duration' => 30,
            'base_price' => 2500,
            'currency' => 'USD',
            'deposit_percent' => 20,
        ]);
        $response = $this->controller->createService($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertNotEmpty($body['id']);
    }

    #[Test]
    public function updateServiceReturnsIdOnSuccess(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $request = $this->buildRequest(Method::PUT, '/admin/booking/services/svc-1', [
            'name' => 'Updated',
            'description' => 'Updated desc',
            'duration' => 45,
            'base_price' => 3000,
            'currency' => 'USD',
            'deposit_percent' => 25,
            'active' => true,
        ], ['id' => 'svc-1']);
        $response = $this->controller->updateService($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('svc-1', $body['id']);
    }

    #[Test]
    public function deleteServiceReturnsNoContent(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $request = $this->buildRequest(Method::DELETE, '/admin/booking/services/svc-2', [], ['id' => 'svc-2']);
        $response = $this->controller->deleteService($request);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function createCategoryReturns400WhenNameEmpty(): void
    {
        $request = $this->buildRequest(Method::POST, '/admin/booking/categories', ['name' => '', 'slug' => '']);
        $response = $this->controller->createCategory($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function createCategoryReturns201WithId(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $request = $this->buildRequest(Method::POST, '/admin/booking/categories', [
            'name' => 'Hair',
            'slug' => 'hair',
            'sort_order' => 1,
        ]);
        $response = $this->controller->createCategory($request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertNotEmpty($body['id']);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, string> $attributes
     */
    private function buildRequest(Method $method, string $path, array $post = [], array $attributes = []): Request
    {
        return new Request(
            method: $method,
            uri: $path,
            path: $path,
            queryString: '',
            headers: new HeaderBag([]),
            body: '',
            post: $post,
            attributes: $attributes,
        );
    }
}
