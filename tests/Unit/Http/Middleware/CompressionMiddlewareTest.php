<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\CompressionMiddleware;

use function gzdecode;
use function str_repeat;
use function strlen;

#[CoversClass(CompressionMiddleware::class)]
final class CompressionMiddlewareTest extends TestCase
{
    #[Test]
    public function compressesGzipWhenAccepted(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('Hello, world! This is compressible content. ', 50);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip, deflate']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
        self::assertStringContainsString('Accept-Encoding', $response->getHeaderLine('Vary'));

        $decoded = gzdecode((string) $response->getBody());
        self::assertSame($body, $decoded);
    }

    #[Test]
    public function skipsSmallResponses(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 1000);
        $body = 'Short';

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame($body, (string) $response->getBody());
    }

    #[Test]
    public function skipsAlreadyEncodedResponses(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('data', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $originalResponse = new Response(body: $body)->withHeader('Content-Encoding', 'br');
        $handler = $this->createHandler($originalResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame('br', $response->getHeaderLine('Content-Encoding'));
    }

    #[Test]
    #[DataProvider('skipContentTypes')]
    public function skipsCompressedContentTypes(string $contentType): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('data', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $originalResponse = new Response(body: $body)->withHeader('Content-Type', $contentType);
        $handler = $this->createHandler($originalResponse);

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Content-Encoding'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skipContentTypes(): iterable
    {
        yield 'image/png' => ['image/png'];
        yield 'image/jpeg' => ['image/jpeg'];
        yield 'video/mp4' => ['video/mp4'];
        yield 'audio/mpeg' => ['audio/mpeg'];
        yield 'application/zip' => ['application/zip'];
        yield 'application/gzip' => ['application/gzip'];
        yield 'font/woff2' => ['font/woff2'];
    }

    #[Test]
    public function passesResponseUnchangedWithNoAcceptEncoding(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('data', 100);

        $request = $this->createRequest([]);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame($body, (string) $response->getBody());
    }

    #[Test]
    public function passesResponseUnchangedWithUnsupportedEncoding(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('data', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'identity']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Content-Encoding'));
    }

    #[Test]
    public function deflateEncodingSupported(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('Compressible deflate data. ', 50);

        $request = $this->createRequest(['Accept-Encoding' => 'deflate']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertSame('deflate', $response->getHeaderLine('Content-Encoding'));

        $decoded = gzinflate((string) $response->getBody());
        self::assertSame($body, $decoded);
    }

    #[Test]
    public function updatesContentLengthAfterCompression(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('Repeated content for compression. ', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        $compressedBody = (string) $response->getBody();
        self::assertSame((string) strlen($compressedBody), $response->getHeaderLine('Content-Length'));
    }

    #[Test]
    public function preservesExistingVaryHeader(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('data ', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $originalResponse = new Response(body: $body)->withHeader('Vary', 'Origin');
        $handler = $this->createHandler($originalResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame('Origin, Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function doesNotDuplicateVaryAcceptEncoding(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('data ', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $originalResponse = new Response(body: $body)->withHeader('Vary', 'Accept-Encoding');
        $handler = $this->createHandler($originalResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function compressesTextHtmlContentType(): void
    {
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('<p>HTML content</p>', 100);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip']);
        $originalResponse = new Response(body: $body)->withHeader('Content-Type', 'text/html; charset=utf-8');
        $handler = $this->createHandler($originalResponse);

        $response = $middleware->process($request, $handler);

        self::assertSame('gzip', $response->getHeaderLine('Content-Encoding'));
    }

    /**
     * @param array<string, string> $headers
     */
    private function createRequest(array $headers): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => $headers[$name] ?? '',
        );

        return $request;
    }

    private function createHandler(ResponseInterface $response): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($response);

        return $handler;
    }
}
