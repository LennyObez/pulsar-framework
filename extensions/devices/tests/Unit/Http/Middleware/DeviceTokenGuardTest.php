<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Devices\Http\Middleware\DeviceTokenGuard;
use Pulsar\Extension\Devices\Internal\DeviceService;
use Pulsar\Extension\Devices\Platform;
use Pulsar\Extension\Devices\UserDevice;
use Pulsar\Extension\Devices\UserDeviceRepositoryInterface;
use Pulsar\Http\Message\Response;

use function json_decode;

#[CoversClass(DeviceTokenGuard::class)]
final class DeviceTokenGuardTest extends TestCase
{
    private UserDeviceRepositoryInterface&Stub $repository;
    private DeviceService $deviceService;
    private DeviceTokenGuard $guard;

    protected function setUp(): void
    {
        $this->repository = $this->createStub(UserDeviceRepositoryInterface::class);
        $this->deviceService = new DeviceService($this->repository);
        $this->guard = new DeviceTokenGuard($this->deviceService);
    }

    #[Test]
    public function returns401WhenAuthorizationHeaderMissing(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Missing', $body['error']);
    }

    #[Test]
    public function returns401WhenNotBearerScheme(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Basic dXNlcjpwYXNz');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenBearerTokenEmpty(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer ');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Empty', $body['error']);
    }

    #[Test]
    public function returns401WhenTokenInvalid(): void
    {
        // Repository returns null for any hash lookup
        $this->repository->method('findByTokenHash')->willReturn(null);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer invalid-token-value');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $this->guard->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertStringContainsString('Invalid', $body['error']);
    }

    #[Test]
    public function delegatesToHandlerWhenTokenValid(): void
    {
        $rawToken = 'abcdef1234567890';
        $hash = bin2hex(sodium_crypto_generichash($rawToken));
        $device = UserDevice::create('user-42', 'Phone', Platform::iOS, '2.0', $hash);
        $this->repository->method('findByTokenHash')->willReturn($device);

        $enrichedRequest = $this->createStub(ServerRequestInterface::class);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('Bearer ' . $rawToken);
        $request->method('withAttribute')->willReturn($enrichedRequest);

        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $this->guard->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
