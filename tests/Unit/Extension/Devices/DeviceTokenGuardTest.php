<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Devices;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Devices\Http\Middleware\DeviceTokenGuard;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;
use Pulsar\Http\Message\Response;

use function bin2hex;
use function json_decode;
use function random_bytes;
use function sodium_crypto_generichash;

use const JSON_THROW_ON_ERROR;

final class DeviceTokenGuardTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    private static function makeDevice(string $hash = 'hash-abc'): UserDevice
    {
        return new UserDevice(
            id: 'dev-001',
            userId: 'user-42',
            deviceName: 'Test Phone',
            platform: Platform::Android,
            appVersion: '2.0.0',
            apiTokenHash: $hash,
            lastSeenAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function authenticatedRequestPassesToHandler(): void
    {
        $rawToken = bin2hex(random_bytes(64));
        $hash = bin2hex(sodium_crypto_generichash($rawToken));
        $device = self::makeDevice($hash);

        $repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn($device);

        $service = new DeviceService($repo);
        $guard = new DeviceTokenGuard($service);

        $expectedResponse = Response::json(['ok' => true]);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer ' . $rawToken);

        $authenticatedRequest = $this->createStub(ServerRequestInterface::class);
        $request->method('withAttribute')->willReturn($authenticatedRequest);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenAuthorizationHeaderMissing(): void
    {
        $repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $service = new DeviceService($repo);
        $guard = new DeviceTokenGuard($service);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Missing or malformed', $error);
    }

    #[Test]
    public function returns401WhenAuthorizationIsNotBearer(): void
    {
        $repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $service = new DeviceService($repo);
        $guard = new DeviceTokenGuard($service);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Basic dXNlcjpwYXNz');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenBearerTokenIsEmpty(): void
    {
        $repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $service = new DeviceService($repo);
        $guard = new DeviceTokenGuard($service);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer ');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Empty bearer token', $error);
    }

    #[Test]
    public function returns401WhenTokenDoesNotMatchAnyDevice(): void
    {
        $repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn(null);

        $service = new DeviceService($repo);
        $guard = new DeviceTokenGuard($service);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer invalid-token-string');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = $this->jsonResponse($response);
        $error = $body['error'];
        self::assertIsString($error);
        self::assertStringContainsString('Invalid device token', $error);
    }

    #[Test]
    public function returns401ForBearerWithoutSpacePrefix(): void
    {
        $repo = $this->createStub(UserDeviceRepositoryInterface::class);
        $service = new DeviceService($repo);
        $guard = new DeviceTokenGuard($service);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearertoken123');

        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }
}
