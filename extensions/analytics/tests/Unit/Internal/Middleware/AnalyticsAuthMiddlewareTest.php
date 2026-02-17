<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Analytics\Internal\Middleware\AnalyticsAuthMiddleware;
use Pulsar\Http\Message\Response;

final class AnalyticsAuthMiddlewareTest extends TestCase
{
    #[Test]
    public function no_identity_returns_401(): void
    {
        $middleware = new AnalyticsAuthMiddleware();
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function anonymous_identity_returns_401(): void
    {
        $middleware = new AnalyticsAuthMiddleware();
        $anonymous = $this->createStub(AnonymousIdentity::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($anonymous);
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function authenticated_without_gate_proceeds(): void
    {
        $middleware = new AnalyticsAuthMiddleware(gate: null);
        $identity = $this->createStub(IdentityInterface::class);
        $identity->method('isAuthenticated')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);

        $expectedResponse = Response::json(['ok' => true]);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function gate_denies_returns_403(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new AnalyticsAuthMiddleware(gate: $gate);
        $identity = $this->createStub(IdentityInterface::class);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);
        $handler = $this->createStub(RequestHandlerInterface::class);

        $response = $middleware->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function gate_allows_proceeds(): void
    {
        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $middleware = new AnalyticsAuthMiddleware(gate: $gate);
        $identity = $this->createStub(IdentityInterface::class);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);

        $expectedResponse = Response::json(['data' => 'ok']);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
