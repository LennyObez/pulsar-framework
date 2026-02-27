<?php

declare(strict_types=1);

namespace Tests\Unit\Extension\Devices;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Extension\Devices\Http\Controller\DeviceController;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;
use Pulsar\Http\Message\Response;

use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DeviceController::class)]
final class DeviceControllerTest extends TestCase
{
    private UserDeviceRepositoryInterface&Stub $deviceRepo;
    private DeviceController $controller;

    protected function setUp(): void
    {
        $this->deviceRepo = $this->createStub(UserDeviceRepositoryInterface::class);
        $service = new DeviceService($this->deviceRepo);
        $this->controller = new DeviceController($service);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(Response $response): array
    {
        $decoded = json_decode($response->getBody()->__toString(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    #[Test]
    public function registerReturns201WithToken(): void
    {
        $this->deviceRepo->method('countByUser')->willReturn(0);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'My Phone',
            'platform' => 'android',
            'app_version' => '2.1.0',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(201, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertNotEmpty($body['token']);
        self::assertIsString($body['token']);

        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('user-1', $data['user_id']);
        self::assertSame('My Phone', $data['device_name']);
        self::assertSame('android', $data['platform']);
        self::assertSame('2.1.0', $data['app_version']);
    }

    #[Test]
    public function indexReturnsPaginatedDeviceList(): void
    {
        $now = new DateTimeImmutable('2026-03-09T10:00:00+00:00');
        $devices = [
            new UserDevice(
                id: 'dev-1',
                userId: 'user-1',
                deviceName: 'Phone',
                platform: Platform::iOS,
                appVersion: '1.0.0',
                apiTokenHash: 'hash-1',
                lastSeenAt: $now,
                createdAt: $now,
            ),
            new UserDevice(
                id: 'dev-2',
                userId: 'user-1',
                deviceName: 'Tablet',
                platform: Platform::Android,
                appVersion: '1.1.0',
                apiTokenHash: 'hash-2',
                lastSeenAt: null,
                createdAt: $now,
            ),
        ];

        $this->deviceRepo->method('findByUser')->willReturn($devices);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertSame('dev-1', $first['id']);
        self::assertSame('ios', $first['platform']);

        $second = $data[1];
        self::assertIsArray($second);
        self::assertSame('dev-2', $second['id']);
        self::assertSame('android', $second['platform']);
    }

    #[Test]
    public function deleteReturns200WithDeletedFlag(): void
    {
        $now = new DateTimeImmutable('2026-03-09T12:00:00+00:00');
        $device = new UserDevice(
            id: 'dev-42',
            userId: 'user-1',
            deviceName: 'Old Phone',
            platform: Platform::Android,
            appVersion: '1.0.0',
            apiTokenHash: 'hash-42',
            lastSeenAt: null,
            createdAt: $now,
        );

        $this->deviceRepo->method('findById')->willReturn($device);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->delete($request, 'dev-42');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('dev-42', $data['id']);
        self::assertTrue($data['deleted']);
    }

    #[Test]
    public function rotateTokenReturns200WithNewToken(): void
    {
        $now = new DateTimeImmutable('2026-03-09T15:00:00+00:00');
        $device = new UserDevice(
            id: 'dev-99',
            userId: 'user-1',
            deviceName: 'Laptop',
            platform: Platform::Web,
            appVersion: '3.0.0',
            apiTokenHash: 'old-hash',
            lastSeenAt: null,
            createdAt: $now,
        );

        $this->deviceRepo->method('findById')->willReturn($device);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->rotate($request, 'dev-99');

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertNotEmpty($body['token']);
        self::assertIsString($body['token']);

        $data = $body['data'];
        self::assertIsArray($data);
        self::assertSame('dev-99', $data['id']);
        self::assertSame('web', $data['platform']);
    }

    #[Test]
    public function registerWithMissingFieldsReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => '',
            'platform' => '',
            'app_version' => '',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);

        $details = $body['details'];
        self::assertIsArray($details);
        self::assertNotNull($details['device_name']);
        self::assertNotNull($details['platform']);
        self::assertNotNull($details['app_version']);
    }

    #[Test]
    public function registerWithInvalidPlatformReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'My Device',
            'platform' => 'windows',
            'app_version' => '1.0.0',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);

        $details = $body['details'];
        self::assertIsArray($details);
        $platform = $details['platform'];
        self::assertIsString($platform);
        self::assertStringContainsString('Invalid platform', $platform);
    }

    #[Test]
    public function registerWithNullBodyReturns422(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn(null);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Validation failed', $body['error']);
    }

    #[Test]
    public function indexWithoutUserIdReturns401(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('');

        $response = $this->controller->index($request);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        self::assertSame('Unauthorized', $body['error']);
    }

    #[Test]
    public function deleteNonexistentDeviceReturns404(): void
    {
        $this->deviceRepo->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->delete($request, 'not-found');

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('not found', $error);
    }

    #[Test]
    public function rotateOnMissingDeviceReturns404(): void
    {
        $this->deviceRepo->method('findById')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->rotate($request, 'xyz');

        self::assertSame(404, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('not found', $error);
    }

    #[Test]
    public function registerWhenDeviceLimitReachedReturns422(): void
    {
        $this->deviceRepo->method('countByUser')->willReturn(5);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'New Device',
            'platform' => 'ios',
            'app_version' => '1.0.0',
        ]);

        $response = $this->controller->register($request);

        self::assertSame(422, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Device limit reached', $error);
    }

    #[Test]
    public function indexReturnsEmptyListForUserWithNoDevices(): void
    {
        $this->deviceRepo->method('findByUser')->willReturn([]);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->index($request);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);
        self::assertCount(0, $data);
    }

    #[Test]
    public function serializationIncludesLastSeenAtWhenPresent(): void
    {
        $now = new DateTimeImmutable('2026-03-09T18:30:00+00:00');
        $device = new UserDevice(
            id: 'dev-seen',
            userId: 'user-1',
            deviceName: 'Active Phone',
            platform: Platform::iOS,
            appVersion: '2.0.0',
            apiTokenHash: 'hash-seen',
            lastSeenAt: $now,
            createdAt: $now,
        );

        $this->deviceRepo->method('findByUser')->willReturn([$device]);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');

        $response = $this->controller->index($request);

        $body = $this->jsonResponse($response);
        $data = $body['data'];
        self::assertIsArray($data);

        $first = $data[0];
        self::assertIsArray($first);
        self::assertNotNull($first['last_seen_at']);
        self::assertSame($now->format('c'), $first['last_seen_at']);
    }

    #[Test]
    public function registerVerifiesDeviceIsSavedToRepository(): void
    {
        /** @var UserDeviceRepositoryInterface&MockObject $repo */
        $repo = $this->createMock(UserDeviceRepositoryInterface::class);
        $repo->method('countByUser')->willReturn(0);
        $repo->expects(self::once())->method('save');

        $service = new DeviceService($repo);
        $controller = new DeviceController($service);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn('user-1');
        $request->method('getParsedBody')->willReturn([
            'device_name' => 'Saved Device',
            'platform' => 'web',
            'app_version' => '1.0.0',
        ]);

        $response = $controller->register($request);

        self::assertSame(201, $response->getStatusCode());
    }
}
