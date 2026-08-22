<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Extension\Eidas\Domain\LevelOfAssurance;
use Pulsar\Extension\Eidas\Middleware\LevelOfAssuranceMiddleware;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\ResponseStatus;

final class LevelOfAssuranceMiddlewareTest extends TestCase
{
    #[Test]
    public function allowsRequestMeetingMinimumLevel(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Substantial);

        $request = new ServerRequest('GET', '/secure')
            ->withAttribute(LevelOfAssuranceMiddleware::LOA_ATTRIBUTE, LevelOfAssurance::High);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function blocksMissingAssuranceLevel(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Low);
        $request = new ServerRequest('GET', '/secure');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function blocksInsufficientLevel(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::High);

        $request = new ServerRequest('GET', '/secure')
            ->withAttribute(LevelOfAssuranceMiddleware::LOA_ATTRIBUTE, LevelOfAssurance::Low);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
    }

    #[Test]
    public function returnsJsonForJsonAcceptHeader(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::High);

        $request = new ServerRequest('GET', '/secure')
            ->withHeader('Accept', 'application/json');

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200));

        $response = $middleware->process($request, $handler);

        self::assertSame(ResponseStatus::Forbidden->value, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function exactLevelMatchPasses(): void
    {
        $middleware = new LevelOfAssuranceMiddleware(LevelOfAssurance::Substantial);

        $request = new ServerRequest('GET', '/secure')
            ->withAttribute(LevelOfAssuranceMiddleware::LOA_ATTRIBUTE, LevelOfAssurance::Substantial);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(statusCode: 200, body: 'OK'));

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }
}
