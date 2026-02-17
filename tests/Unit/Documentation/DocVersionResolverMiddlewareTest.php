<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Documentation\DocVersion;
use Pulsar\Documentation\DocVersionRegistry;
use Pulsar\Documentation\DocVersionResolverMiddleware;

#[CoversClass(DocVersionResolverMiddleware::class)]
final class DocVersionResolverMiddlewareTest extends TestCase
{
    #[Test]
    public function addsDocVersionAttributeForMatchingPath(): void
    {
        $version = new DocVersion('1.0', 'v1', '/docs/1.0', isLatest: true);

        $registry = new DocVersionRegistry();
        $registry->register($version);

        $middleware = new DocVersionResolverMiddleware($registry);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/docs/1.0/getting-started');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $capturedDocVersion = null;
        $capturedDocPath = null;

        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use ($request, &$capturedDocVersion, &$capturedDocPath): ServerRequestInterface {
                if ($name === 'doc_version') {
                    $capturedDocVersion = $value;
                }
                if ($name === 'doc_path') {
                    $capturedDocPath = $value;
                }

                return $request;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
        self::assertInstanceOf(DocVersion::class, $capturedDocVersion);
        self::assertSame('getting-started', $capturedDocPath);
    }

    #[Test]
    public function passesThruNonDocPath(): void
    {
        $registry = new DocVersionRegistry();
        $middleware = new DocVersionResolverMiddleware($registry);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/about');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $result = $middleware->process($request, $handler);

        self::assertSame($response, $result);
    }

    #[Test]
    public function handlesDocPathWithNoSlug(): void
    {
        $version = new DocVersion('2.0', 'v2', '/docs/2.0', isLatest: true);
        $registry = new DocVersionRegistry();
        $registry->register($version);

        $middleware = new DocVersionResolverMiddleware($registry);

        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn('/docs/2.0');

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);

        $capturedDocPath = 'not-set';

        $request->method('withAttribute')->willReturnCallback(
            function (string $name, mixed $value) use ($request, &$capturedDocPath): ServerRequestInterface {
                if ($name === 'doc_path') {
                    $capturedDocPath = $value;
                }

                return $request;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        $middleware->process($request, $handler);

        self::assertSame('', $capturedDocPath);
    }
}
