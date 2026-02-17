<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Server\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Extension\Studio\Config\StudioSecurityConfig;
use Pulsar\Extension\Studio\Security\StudioAccessGate;
use Pulsar\Extension\Studio\Server\Middleware\StudioAccessMiddleware;
use Pulsar\Http\Message\Response;

#[CoversClass(StudioAccessMiddleware::class)]
final class StudioAccessMiddlewareTest extends TestCase
{
    #[Test]
    public function allowedRequestPassesThroughInLocalMode(): void
    {
        $gate = new StudioAccessGate(new StudioSecurityConfig(), EnvironmentMode::Local);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('');

        $expectedResponse = new Response(statusCode: 200, body: 'ok');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function authenticationRequiredReturns401InStagingWithAuth(): void
    {
        $config = new StudioSecurityConfig(
            authRequired: true,
            username: 'admin',
            password: 'secret',
        );
        $gate = new StudioAccessGate($config, EnvironmentMode::Staging);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Basic realm="Pulsar Studio"', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function productionWithoutConfirmReturns403(): void
    {
        $config = new StudioSecurityConfig(productionConfirm: false);
        $gate = new StudioAccessGate($config, EnvironmentMode::Production);
        $middleware = new StudioAccessMiddleware($gate);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '127.0.0.1']);
        $request->method('getHeaderLine')->willReturn('');
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }
}
