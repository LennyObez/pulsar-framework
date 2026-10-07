<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Analytics\Internal\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\AnonymousIdentity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Analytics\Internal\Middleware\AnalyticsAuthMiddleware;
use Pulsar\Http\Message\Response;

#[CoversClass(AnalyticsAuthMiddleware::class)]
final class AnalyticsAuthMiddlewareTest extends TestCase
{
    private RequestHandlerInterface&Stub $handler;
    private ResponseInterface $normalResponse;

    protected function setUp(): void
    {
        $this->handler = $this->createStub(RequestHandlerInterface::class);
        $this->normalResponse = Response::json(['data' => 'ok']);
        $this->handler->method('handle')->willReturn($this->normalResponse);
    }

    #[Test]
    public function returns401WhenNoIdentity(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(null);

        $middleware = new AnalyticsAuthMiddleware();
        $response = $middleware->process($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401ForAnonymousIdentity(): void
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn(new AnonymousIdentity());

        $middleware = new AnalyticsAuthMiddleware();
        $response = $middleware->process($request, $this->handler);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughForAuthenticatedIdentityWithoutGate(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);

        $middleware = new AnalyticsAuthMiddleware();
        $response = $middleware->process($request, $this->handler);

        self::assertSame($this->normalResponse, $response);
    }

    #[Test]
    public function returns403WhenGateDenies(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(true);

        $middleware = new AnalyticsAuthMiddleware($gate);
        $response = $middleware->process($request, $this->handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughWhenGateAllows(): void
    {
        $identity = $this->createStub(IdentityInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturn($identity);

        $gate = $this->createStub(GateInterface::class);
        $gate->method('denies')->willReturn(false);

        $middleware = new AnalyticsAuthMiddleware($gate);
        $response = $middleware->process($request, $this->handler);

        self::assertSame($this->normalResponse, $response);
    }
}
