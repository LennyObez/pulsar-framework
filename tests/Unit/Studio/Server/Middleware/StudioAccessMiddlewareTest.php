<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Server\Middleware;

use function base64_encode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Config\StudioSecurityConfig;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;
use Pulsar\Studio\Security\StudioAccessGate;
use Pulsar\Studio\Server\Middleware\StudioAccessMiddleware;

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
        $expectedResponse = new Response(body: 'OK', status: ResponseStatus::OK);
        $handlerCalled = false;

        $response = $middleware->process($request, function (Request $req) use (&$handlerCalled, $expectedResponse): Response {
            $handlerCalled = true;

            return $expectedResponse;
        });

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
        $handlerCalled = false;

        $response = $middleware->process($request, function () use (&$handlerCalled): Response {
            $handlerCalled = true;

            return new Response(body: 'OK', status: ResponseStatus::OK);
        });

        self::assertFalse($handlerCalled);
        self::assertSame(ResponseStatus::Unauthorized, $response->status);
        self::assertSame('Authentication required', $response->body);
        self::assertSame('Basic realm="Pulsar Studio"', $response->headers->first('WWW-Authenticate'));
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
        $handlerCalled = false;

        $response = $middleware->process($request, function () use (&$handlerCalled): Response {
            $handlerCalled = true;

            return new Response(body: 'OK', status: ResponseStatus::OK);
        });

        self::assertFalse($handlerCalled);
        self::assertSame(ResponseStatus::Forbidden, $response->status);
        self::assertSame('IP not in allowlist', $response->body);
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
        $handlerCalled = false;

        $response = $middleware->process($request, function () use (&$handlerCalled): Response {
            $handlerCalled = true;

            return new Response(body: 'OK', status: ResponseStatus::OK);
        });

        self::assertFalse($handlerCalled);
        self::assertSame(ResponseStatus::Forbidden, $response->status);
        self::assertSame('Production mode requires STUDIO_PRODUCTION_CONFIRM=true', $response->body);
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
        $expectedResponse = new Response(body: 'OK', status: ResponseStatus::OK);
        $handlerCalled = false;

        $response = $middleware->process($request, function () use (&$handlerCalled, $expectedResponse): Response {
            $handlerCalled = true;

            return $expectedResponse;
        });

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
        $expectedResponse = new Response(body: 'OK', status: ResponseStatus::OK);
        $handlerCalled = false;

        $response = $middleware->process($request, function () use (&$handlerCalled, $expectedResponse): Response {
            $handlerCalled = true;

            return $expectedResponse;
        });

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

        $middleware->process($request, function (Request $req) use (&$capturedRequest): Response {
            $capturedRequest = $req;

            return new Response(body: 'OK', status: ResponseStatus::OK);
        });

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
            body: 'Custom Response',
            status: ResponseStatus::Created,
            headers: new HeaderBag(['X-Custom' => 'value']),
        );

        $response = $middleware->process($request, fn(): Response => $expectedResponse);

        self::assertSame($expectedResponse, $response);
        self::assertSame(ResponseStatus::Created, $response->status);
        self::assertSame('Custom Response', $response->body);
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

        $middleware->process($request, function () use (&$handlerCalled): Response {
            $handlerCalled = true;

            return new Response(body: 'OK', status: ResponseStatus::OK);
        });

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
        $expectedResponse = new Response(body: 'OK', status: ResponseStatus::OK);
        $handlerCalled = false;

        $response = $middleware->process($request, function () use (&$handlerCalled, $expectedResponse): Response {
            $handlerCalled = true;

            return $expectedResponse;
        });

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

        $response = $middleware->process($request, fn(): Response => new Response(body: 'OK', status: ResponseStatus::OK));

        self::assertSame(ResponseStatus::Forbidden, $response->status);
        self::assertNull($response->headers->first('WWW-Authenticate'));
    }

    private function createRequest(
        ?string $remoteAddr = '127.0.0.1',
        ?string $authHeader = null,
    ): Request {
        $headers = new HeaderBag($authHeader !== null ? ['Authorization' => $authHeader] : []);

        $server = [];
        if ($remoteAddr !== null) {
            $server['REMOTE_ADDR'] = $remoteAddr;
        }

        return new Request(
            method: Method::GET,
            uri: '/studio',
            path: '/studio',
            queryString: '',
            headers: $headers,
            body: '',
            server: $server,
        );
    }
}
