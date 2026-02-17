<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit\Http\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Devices\Http\Controller\DeviceController;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;

use function json_decode;

#[CoversClass(DeviceController::class)]
final class DeviceControllerTest extends TestCase
{
    private UserDeviceRepositoryInterface&Stub $repository;
    private DeviceService $service;
    private DeviceController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(UserDeviceRepositoryInterface::class);
        $this->service = new DeviceService($this->repository, maxDevicesPerUser: 5);
        $this->controller = new DeviceController($this->service);
    }

    #[Test]
    public function indexReturns401WhenNoUserId(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('');

        $response = $this->controller->index($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function indexReturnsDeviceList(): void
    {
        $devices = [
            UserDevice::create('u1', 'Phone', Platform::iOS, '2.0', 'hash1'),
            UserDevice::create('u1', 'Tablet', Platform::Android, '1.5', 'hash2'),
        ];
        $this->repository->method('findByUser')->willReturn($devices);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertCount(2, $body['data']);
        self::assertSame('Phone', $body['data'][0]['device_name']);
    }

    #[Test]
    public function registerReturns401WhenNoUserId(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('');
        $request->method('getParsedBody')->willReturn([]);

        $response = $this->controller->register($request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function registerReturns422WhenFieldsMissing(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => '',
            'platform' => '',
            'app_version' => '',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('Validation failed', $body['error']);
    }

    #[Test]
    public function registerReturns422ForInvalidPlatform(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'My Phone',
            'platform' => 'windows',
            'app_version' => '1.0',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Invalid platform', $body['details']['platform']);
    }

    #[Test]
    public function registerReturns201OnSuccess(): void
    {
        $this->repository->method('countByUser')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'Phone',
            'platform' => 'ios',
            'app_version' => '2.0',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(201, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('token', $body);
        self::assertSame('Phone', $body['data']['device_name']);
    }

    #[Test]
    public function registerReturns422WhenDeviceLimitReached(): void
    {
        $this->repository->method('countByUser')->willReturn(5);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'Phone',
            'platform' => 'android',
            'app_version' => '1.0',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rotateReturns401WhenNoUserId(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('');

        $response = $this->controller->rotate($request, 'device-id');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function rotateReturnsNewToken(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::Web, '1.0', 'oldhash');
        $this->repository->method('findById')->willReturn($device);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');

        $response = $this->controller->rotate($request, $device->id);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertArrayHasKey('token', $body);
    }

    #[Test]
    public function rotateReturns404WhenNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');

        $response = $this->controller->rotate($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturns401WhenNoUserId(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('');

        $response = $this->controller->delete($request, 'device-id');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function deleteReturnsSuccessResponse(): void
    {
        $device = UserDevice::create('u1', 'Phone', Platform::iOS, '1.0', 'hash');
        $this->repository->method('findById')->willReturn($device);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');

        $response = $this->controller->delete($request, $device->id);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['data']['deleted']);
    }

    #[Test]
    public function deleteReturns404WhenNotFound(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('u1');

        $response = $this->controller->delete($request, 'nonexistent');

        self::assertSame(404, $response->getStatusCode());
    }
}
