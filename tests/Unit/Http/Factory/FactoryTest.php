<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Factory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use Pulsar\Http\Factory\RequestFactory;
use Pulsar\Http\Factory\ResponseFactory;
use Pulsar\Http\Factory\ServerRequestFactory;
use Pulsar\Http\Factory\StreamFactory;
use Pulsar\Http\Factory\UploadedFileFactory;
use Pulsar\Http\Factory\UriFactory;
use Pulsar\Http\Message\Stream;
use Pulsar\Http\Message\Uri;

#[CoversClass(RequestFactory::class)]
#[CoversClass(ResponseFactory::class)]
#[CoversClass(ServerRequestFactory::class)]
#[CoversClass(StreamFactory::class)]
#[CoversClass(UploadedFileFactory::class)]
#[CoversClass(UriFactory::class)]
final class FactoryTest extends TestCase
{
    // ── RequestFactory ────────────────────────────────────────────────

    #[Test]
    public function requestFactoryCreatesWithStringUri(): void
    {
        $factory = new RequestFactory();
        $request = $factory->createRequest('GET', 'https://example.com/path');

        self::assertSame('GET', $request->getMethod());
        self::assertSame('example.com', $request->getUri()->getHost());
        self::assertSame('/path', $request->getUri()->getPath());
    }

    #[Test]
    public function requestFactoryCreatesWithUriInstance(): void
    {
        $factory = new RequestFactory();
        $uri = Uri::fromString('https://example.com/api');
        $request = $factory->createRequest('POST', $uri);

        self::assertSame('POST', $request->getMethod());
        self::assertSame($uri, $request->getUri());
    }

    // ── ResponseFactory ───────────────────────────────────────────────

    #[Test]
    public function responseFactoryCreatesDefaultResponse(): void
    {
        $factory = new ResponseFactory();
        $response = $factory->createResponse();

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
    }

    #[Test]
    public function responseFactoryCreatesWithStatusAndReason(): void
    {
        $factory = new ResponseFactory();
        $response = $factory->createResponse(404, 'Not Found');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getReasonPhrase());
    }

    #[Test]
    public function responseFactoryUsesDefaultReasonPhrase(): void
    {
        $factory = new ResponseFactory();
        $response = $factory->createResponse(201);

        self::assertSame('Created', $response->getReasonPhrase());
    }

    // ── ServerRequestFactory ──────────────────────────────────────────

    #[Test]
    public function serverRequestFactoryCreatesWithStringUri(): void
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', 'https://example.com/api');

        self::assertInstanceOf(ServerRequestInterface::class, $request);
        self::assertSame('GET', $request->getMethod());
        self::assertSame('example.com', $request->getUri()->getHost());
    }

    #[Test]
    public function serverRequestFactoryCreatesWithUriInstance(): void
    {
        $factory = new ServerRequestFactory();
        $uri = Uri::fromString('https://example.com/api');
        $request = $factory->createServerRequest('POST', $uri, ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertSame('POST', $request->getMethod());
        self::assertSame($uri, $request->getUri());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
    }

    // ── StreamFactory ─────────────────────────────────────────────────

    #[Test]
    public function streamFactoryCreatesFromString(): void
    {
        $factory = new StreamFactory();
        $stream = $factory->createStream('hello');

        self::assertInstanceOf(StreamInterface::class, $stream);
        self::assertSame('hello', (string) $stream);
    }

    #[Test]
    public function streamFactoryCreatesFromFile(): void
    {
        $factory = new StreamFactory();
        $stream = $factory->createStreamFromFile('php://temp', 'r+');

        self::assertInstanceOf(StreamInterface::class, $stream);
        self::assertTrue($stream->isReadable());
    }

    #[Test]
    public function streamFactoryCreatesFromResource(): void
    {
        $resource = fopen('php://temp', 'r+b');
        self::assertNotFalse($resource);
        fwrite($resource, 'resource content');
        rewind($resource);

        $factory = new StreamFactory();
        $stream = $factory->createStreamFromResource($resource);

        self::assertInstanceOf(StreamInterface::class, $stream);
        self::assertSame('resource content', (string) $stream);
    }

    // ── UploadedFileFactory ───────────────────────────────────────────

    #[Test]
    public function uploadedFileFactoryCreatesFromStream(): void
    {
        $factory = new UploadedFileFactory();
        $stream = Stream::create('file data');

        $file = $factory->createUploadedFile(
            stream: $stream,
            size: 9,
            error: UPLOAD_ERR_OK,
            clientFilename: 'test.txt',
            clientMediaType: 'text/plain',
        );

        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame(9, $file->getSize());
        self::assertSame(UPLOAD_ERR_OK, $file->getError());
        self::assertSame('test.txt', $file->getClientFilename());
        self::assertSame('text/plain', $file->getClientMediaType());
    }

    #[Test]
    public function uploadedFileFactoryDefaultsSizeFromStream(): void
    {
        $factory = new UploadedFileFactory();
        $stream = Stream::create('12345');

        $file = $factory->createUploadedFile($stream);

        self::assertSame(5, $file->getSize());
    }

    // ── UriFactory ────────────────────────────────────────────────────

    #[Test]
    public function uriFactoryCreatesFromString(): void
    {
        $factory = new UriFactory();
        $uri = $factory->createUri('https://example.com:8443/path?q=1#frag');

        self::assertInstanceOf(UriInterface::class, $uri);
        self::assertSame('https', $uri->getScheme());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('/path', $uri->getPath());
        self::assertSame('q=1', $uri->getQuery());
        self::assertSame('frag', $uri->getFragment());
    }

    #[Test]
    public function uriFactoryCreatesEmptyUri(): void
    {
        $factory = new UriFactory();
        $uri = $factory->createUri();

        self::assertSame('', $uri->getScheme());
        self::assertSame('', $uri->getHost());
    }
}
