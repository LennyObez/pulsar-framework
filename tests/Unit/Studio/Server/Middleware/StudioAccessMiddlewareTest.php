<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Extension\Studio\Server\Middleware\StudioAccessMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

use function base64_encode;

#[CoversClass(StudioAccessMiddleware::class)]
final class StudioAccessMiddlewareTest extends TestCase
{
    #[Test]
    public function processAllowsAccessWhenGateAllows(): void
    {
        $config = new StudioSecurityConfig();
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest();
        $expectedResponse = new Response(statusCode: 200, body: 'OK');
        $handlerCalled = false;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$handlerCalled, $expectedResponse): ResponseInterface {
                $handlerCalled = true;

                return $expectedResponse;
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function processReturnsUnauthorizedWhenAuthenticationRequired(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Unauthorized->value, $response->getStatusCode());
        self::assertSame('Authentication required', (string) $response->getBody());
        self::assertSame('Basic realm="Pulsar Studio"', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function processReturnsForbiddenWhenAccessDeniedForOtherReasons(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest(remoteAddr: '192.168.1.100');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('IP not in allowlist', (string) $response->getBody());
    }

    #[Test]
    public function processReturnsForbiddenForProductionWithoutConfirm(): void
    {
        $config = new StudioSecurityConfig(
            productionConfirm: false,
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertSame('Production mode requires STUDIO_PRODUCTION_CONFIRM=true', (string) $response->getBody());
    }

    #[Test]
    public function processAllowsAccessWithValidCredentials(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: [],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $middleware = new StudioAccessMiddleware($gate);

        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(authHeader: "Basic {$credentials}");
        $expectedResponse = new Response(statusCode: 200, body: 'OK');
        $handlerCalled = false;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function () use (&$handlerCalled, $expectedResponse): ResponseInterface {
                $handlerCalled = true;

                return $expectedResponse;
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function processAllowsAccessWhenIpInAllowlist(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest(remoteAddr: '10.5.3.1');
        $expectedResponse = new Response(statusCode: 200, body: 'OK');
        $handlerCalled = false;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function () use (&$handlerCalled, $expectedResponse): ResponseInterface {
                $handlerCalled = true;

                return $expectedResponse;
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function processPassesRequestToNextHandler(): void
    {
        $config = new StudioSecurityConfig();
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest();
        $capturedRequest = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function (ServerRequestInterface $req) use (&$capturedRequest): ResponseInterface {
                $capturedRequest = $req;

                return new Response(statusCode: 200, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertSame($request, $capturedRequest);
    }

    #[Test]
    public function processReturnsResponseFromNextHandler(): void
    {
        $config = new StudioSecurityConfig();
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest();
        $expectedResponse = new Response(
            statusCode: 201,
            headers: ['X-Custom' => 'value'],
            body: 'Custom Response',
        );

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
        self::assertSame(ResponseStatus::Created->value, $response->getStatusCode());
        self::assertSame('Custom Response', (string) $response->getBody());
    }

    #[Test]
    public function processLocalModeAllowsAllRequests(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Local);
        $middleware = new StudioAccessMiddleware($gate);

        // Request without auth and from non-allowed IP
        $request = $this->createRequest(remoteAddr: '192.168.1.100');
        $handlerCalled = false;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function () use (&$handlerCalled): ResponseInterface {
                $handlerCalled = true;

                return new Response(statusCode: 200, body: 'OK');
            },
        );

        $middleware->process($request, $handler);

        self::assertTrue($handlerCalled, 'Local mode should allow all requests regardless of other checks');
    }

    #[Test]
    public function processProductionModeWithAllChecksPassing(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
            allowedCidrs: ['10.0.0.0/8'],
            productionConfirm: true,
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $middleware = new StudioAccessMiddleware($gate);

        $credentials = base64_encode('admin:secret');
        $request = $this->createRequest(
            remoteAddr: '10.5.3.1',
            authHeader: "Basic {$credentials}",
        );
        $expectedResponse = new Response(statusCode: 200, body: 'OK');
        $handlerCalled = false;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())->method('handle')->willReturnCallback(
            function () use (&$handlerCalled, $expectedResponse): ResponseInterface {
                $handlerCalled = true;

                return $expectedResponse;
            },
        );

        $response = $middleware->process($request, $handler);

        self::assertTrue($handlerCalled);
        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function processDoesNotAddWwwAuthenticateHeaderForNonAuthDenials(): void
    {
        $config = new StudioSecurityConfig(
            allowedCidrs: ['10.0.0.0/8'],
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createRequest(remoteAddr: '192.168.1.100');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertFalse($response->hasHeader('WWW-Authenticate'));
    }

    private function createRequest(
        ?string $remoteAddr = '127.0.0.1',
        ?string $authHeader = null,
    ): ServerRequest {
        $headers = $authHeader !== null ? ['Authorization' => $authHeader] : [];

        $serverParams = [];
        if ($remoteAddr !== null) {
            $serverParams['REMOTE_ADDR'] = $remoteAddr;
        }

        return new ServerRequest(
            method: 'GET',
            uri: '/studio',
            headers: $headers,
            serverParams: $serverParams,
        );
    }
}
