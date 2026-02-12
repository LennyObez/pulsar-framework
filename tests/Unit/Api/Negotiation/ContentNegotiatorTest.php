<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Negotiation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Api\Format\HalRenderer;
use Pulsar\Api\Format\JsonApiRenderer;
use Pulsar\Api\Format\JsonRenderer;
use Pulsar\Api\Format\ResponseRendererInterface;
use Pulsar\Api\Negotiation\ContentNegotiator;

#[CoversClass(ContentNegotiator::class)]
final class ContentNegotiatorTest extends TestCase
{
    private function createRequest(string $acceptHeader): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')
            ->willReturnCallback(static function (string $name) use ($acceptHeader): string {
                if (strtolower($name) === 'accept') {
                    return $acceptHeader;
                }
                return '';
            });
        $request->method('withAttribute')->willReturnSelf();

        return $request;
    }

    private function createHandler(): RequestHandlerInterface
    {
        $body = $this->createStub(StreamInterface::class);
        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }

    #[Test]
    public function defaultsToJsonRendererForEmptyAccept(): void
    {
        $negotiator = new ContentNegotiator(jsonApiEnabled: true, halEnabled: true);
        $request = $this->createRequest('');
        $handler = $this->createHandler();

        // The negotiator sets renderer on request attribute; we verify by capturing
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function defaultsToJsonRendererForWildcard(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('*/*');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator();
        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function selectsJsonApiWhenEnabled(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/vnd.api+json');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator(jsonApiEnabled: true);
        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonApiRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function fallsBackToDefaultWhenJsonApiDisabled(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/vnd.api+json');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator(jsonApiEnabled: false);
        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function selectsHalWhenEnabled(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/hal+json');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator(halEnabled: true);
        $negotiator->process($request, $handler);

        self::assertInstanceOf(HalRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function selectsJsonForApplicationJson(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator(jsonApiEnabled: true, halEnabled: true);
        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function fallsBackToDefaultForUnknownAcceptHeader(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('text/xml');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator();
        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function jsonApiHasPriorityOverPlainJson(): void
    {
        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('application/vnd.api+json, application/json');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator(jsonApiEnabled: true);
        $negotiator->process($request, $handler);

        self::assertInstanceOf(JsonApiRenderer::class, $capturedRenderer);
    }

    #[Test]
    public function customDefaultRenderer(): void
    {
        $customRenderer = $this->createStub(ResponseRendererInterface::class);
        $customRenderer->method('contentType')->willReturn('text/csv');

        $capturedRenderer = null;
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturn('text/xml');
        $request->method('withAttribute')
            ->willReturnCallback(function (string $name, mixed $value) use ($request, &$capturedRenderer) {
                if ($name === ContentNegotiator::RENDERER_ATTRIBUTE) {
                    $capturedRenderer = $value;
                }
                return $request;
            });

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnSelf();
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $negotiator = new ContentNegotiator(defaultRenderer: $customRenderer);
        $negotiator->process($request, $handler);

        self::assertSame($customRenderer, $capturedRenderer);
    }
}
