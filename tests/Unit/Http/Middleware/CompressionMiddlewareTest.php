<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\CompressionMiddleware;
use ReflectionMethod;

use function brotli_compress;
use function brotli_uncompress;
use function gzdecode;
use function gzuncompress;
use function sprintf;
use function str_repeat;
use function strlen;
use function zstd_compress;
use function zstd_uncompress;

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
    #[RequiresPhpExtension('brotli')]
    public function compressesBrotliAtTheDynamicQualityNotTheExtensionDefault(): void
    {
        // Regression guard. brotli_compress() defaults to quality 11, which is
        // built for compressing a static asset once at build time: measured, it
        // costs ~105ms of CPU on a 51KB page (~86x gzip-5) to save ~16% of
        // bytes. Because 'br' is first in server preference and every browser
        // offers it, dropping the quality argument silently opts EVERY dynamic
        // response into that. Pin the quality so it cannot regress.
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('Hello, world! This is compressible content. ', 200);

        $request = $this->createRequest(['Accept-Encoding' => 'br']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);
        $encoded = (string) $response->getBody();

        self::assertSame('br', $response->getHeaderLine('Content-Encoding'));
        self::assertSame($body, brotli_uncompress($encoded), 'round-trip must survive');
        self::assertSame(brotli_compress($body, 5), $encoded, 'must compress at quality 5');
        self::assertNotSame(
            brotli_compress($body),
            $encoded,
            'must NOT use the extension default quality (11) on dynamic responses',
        );
    }

    #[Test]
    #[RequiresPhpExtension('brotli')]
    public function brotliQualityIsTunable(): void
    {
        $body = str_repeat('Hello, world! This is compressible content. ', 200);

        $middleware = new CompressionMiddleware(minimumBytes: 10, brotliQuality: 2);
        $response = $middleware->process(
            $this->createRequest(['Accept-Encoding' => 'br']),
            $this->createHandler(new Response(body: $body)),
        );

        self::assertSame(brotli_compress($body, 2), (string) $response->getBody());
    }

    #[Test]
    #[RequiresPhpExtension('zstd')]
    public function compressesZstdAtTheConfiguredLevel(): void
    {
        $body = str_repeat('Hello, world! This is compressible content. ', 200);

        $middleware = new CompressionMiddleware(minimumBytes: 10, zstdLevel: 1);
        $response = $middleware->process(
            $this->createRequest(['Accept-Encoding' => 'zstd']),
            $this->createHandler(new Response(body: $body)),
        );

        $encoded = (string) $response->getBody();

        self::assertSame('zstd', $response->getHeaderLine('Content-Encoding'));
        self::assertSame($body, zstd_uncompress($encoded), 'round-trip must survive');
        self::assertSame(zstd_compress($body, 1), $encoded, 'must compress at the configured level');
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
    public function deflateEncodingIsZlibWrapped(): void
    {
        // Content-Encoding: deflate means zlib-wrapped (RFC 1950), which is
        // what gzuncompress() reads. gzdeflate() emits raw DEFLATE (RFC 1951)
        // instead: the two are one function name apart and only the wrapped
        // form is decodable by a conforming client.
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('Compressible deflate data. ', 50);

        $request = $this->createRequest(['Accept-Encoding' => 'deflate']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertSame('deflate', $response->getHeaderLine('Content-Encoding'));

        $decoded = gzuncompress((string) $response->getBody());
        self::assertSame($body, $decoded);
    }

    #[Test]
    public function refusesEncodingWithZeroQValue(): void
    {
        // gzip;q=0 is an explicit refusal (RFC 9110); the body must be sent
        // uncompressed. Parsing Accept-Encoding by token alone misses the
        // q-value and compresses against the client's stated wishes.
        $middleware = new CompressionMiddleware(minimumBytes: 10);
        $body = str_repeat('compressible content here ', 50);

        $request = $this->createRequest(['Accept-Encoding' => 'gzip;q=0']);
        $handler = $this->createHandler(new Response(body: $body));

        $response = $middleware->process($request, $handler);

        self::assertFalse($response->hasHeader('Content-Encoding'));
        self::assertSame($body, (string) $response->getBody());
    }

    #[Test]
    public function everyNegotiableEncodingIsProducible(): void
    {
        // The capability test driving negotiation must agree with what compress()
        // can actually deliver, so an installed codec (brotli/zstd) is both
        // negotiated and used, and a negotiated encoding is never sent
        // uncompressed. gzip/deflate are always available; br/zstd appear only
        // when their extension is loaded.
        $middleware = new CompressionMiddleware(minimumBytes: 1);

        $available = new ReflectionMethod($middleware, 'availableEncodings')->invoke($middleware);
        self::assertIsArray($available);
        self::assertContains('gzip', $available);
        self::assertContains('deflate', $available);

        $compress = new ReflectionMethod($middleware, 'compress');

        foreach ($available as $encoding) {
            self::assertIsString($encoding);
            self::assertNotNull(
                $compress->invoke($middleware, 'some compressible payload to encode', $encoding),
                sprintf('Negotiable encoding "%s" must be producible by compress()', $encoding),
            );
        }
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
