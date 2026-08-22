<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Psd2\Middleware\ScaRequiredMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

final class ScaRequiredMiddlewareTest extends TestCase
{
    #[Test]
    public function allowsRequestWithScaVerifiedAttribute(): void
    {
        $middleware = new ScaRequiredMiddleware();

        $request = $this->createRequest()
            ->withAttribute(ScaRequiredMiddleware::REQUEST_ATTRIBUTE, true);

        $expectedResponse = new Response(statusCode: 200, body: 'OK');
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function blocksMissingScaTokenWithForbidden(): void
    {
        $middleware = new ScaRequiredMiddleware();
        $request = $this->createRequest();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function returnsJsonForJsonAcceptHeader(): void
    {
        $middleware = new ScaRequiredMiddleware();
        $request = $this->createRequest()->withHeader('Accept', 'application/json');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function tokenWithoutVerificationAttributeStillBlocks(): void
    {
        $middleware = new ScaRequiredMiddleware();
        $request = $this->createRequest()
            ->withHeader(ScaRequiredMiddleware::SCA_TOKEN_HEADER, 'some_token');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    private function createRequest(): ServerRequestInterface
    {
        return new ServerRequest('GET', '/api/payment');
    }
}
