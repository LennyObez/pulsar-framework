<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Response;

use ArrayIterator;
use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\StringStream;
use Pulsar\Http\Response\StreamedResponse;
use RuntimeException;

#[CoversClass(StreamedResponse::class)]
final class StreamedResponseTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaults(): void
    {
        $source = new ArrayIterator(['chunk1', 'chunk2']);
        $response = new StreamedResponse($source);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getReasonPhrase());
        self::assertSame('1.1', $response->getProtocolVersion());
        self::assertSame('chunked', $response->getHeaderLine('Transfer-Encoding'));
    }

    #[Test]
    public function constructorAcceptsCustomHeaders(): void
    {
        $response = new StreamedResponse(
            new ArrayIterator([]),
            statusCode: 201,
            headers: [
                'Content-Type' => 'application/json',
                'X-Custom' => ['a', 'b'],
            ],
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('a, b', $response->getHeaderLine('X-Custom'));
    }

    #[Test]
    public function fromGeneratorCreatesResponse(): void
    {
        $response = StreamedResponse::fromGenerator(
            static function (): Generator {
                yield 'line1';
                yield 'line2';
            },
            headers: ['Content-Type' => 'text/plain'],
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function getSourceReturnsIterator(): void
    {
        $source = new ArrayIterator(['a', 'b', 'c']);
        $response = new StreamedResponse($source);

        self::assertSame($source, $response->getSource());
    }

    #[Test]
    public function getBodyMaterializesAllChunks(): void
    {
        $response = StreamedResponse::fromGenerator(static function (): Generator {
            yield 'Hello';
            yield ', ';
            yield 'World!';
        });

        $body = (string) $response->getBody();
        self::assertSame('Hello, World!', $body);
    }

    #[Test]
    public function withStatusReturnsNewInstance(): void
    {
        $original = new StreamedResponse(new ArrayIterator([]));
        $modified = $original->withStatus(404, 'Not Found');

        self::assertSame(200, $original->getStatusCode());
        self::assertSame(404, $modified->getStatusCode());
        self::assertSame('Not Found', $modified->getReasonPhrase());
    }

    #[Test]
    public function withStatusUsesDefaultReasonPhrase(): void
    {
        $response = new StreamedResponse(new ArrayIterator([]))->withStatus(204);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('No Content', $response->getReasonPhrase());
    }

    #[Test]
    public function withProtocolVersionReturnsNewInstance(): void
    {
        $original = new StreamedResponse(new ArrayIterator([]));
        $modified = $original->withProtocolVersion('2.0');

        self::assertSame('1.1', $original->getProtocolVersion());
        self::assertSame('2.0', $modified->getProtocolVersion());
    }

    #[Test]
    public function headerOperationsWork(): void
    {
        $response = new StreamedResponse(new ArrayIterator([]), headers: ['X-Foo' => 'bar']);

        self::assertTrue($response->hasHeader('X-Foo'));
        self::assertSame(['bar'], $response->getHeader('X-Foo'));
        self::assertSame('bar', $response->getHeaderLine('X-Foo'));

        $withAdded = $response->withAddedHeader('X-Foo', 'baz');
        self::assertSame('bar, baz', $withAdded->getHeaderLine('X-Foo'));

        $withNew = $response->withHeader('X-New', 'value');
        self::assertTrue($withNew->hasHeader('X-New'));
        self::assertFalse($response->hasHeader('X-New'));

        $without = $withNew->withoutHeader('X-New');
        self::assertFalse($without->hasHeader('X-New'));
    }

    #[Test]
    public function getHeadersReturnsOriginalCasing(): void
    {
        $response = new StreamedResponse(
            new ArrayIterator([]),
            headers: ['Content-Type' => 'text/html', 'X-Request-Id' => 'abc'],
        );

        $headers = $response->getHeaders();
        self::assertArrayHasKey('Content-Type', $headers);
        self::assertArrayHasKey('X-Request-Id', $headers);
    }

    #[Test]
    public function withBodyThrowsRuntimeException(): void
    {
        $response = new StreamedResponse(new ArrayIterator([]));

        $this->expectException(RuntimeException::class);
        (void) $response->withBody(new StringStream('nope'));
    }

    #[Test]
    public function missingHeaderReturnsEmptyArray(): void
    {
        $response = new StreamedResponse(new ArrayIterator([]));

        self::assertSame([], $response->getHeader('X-Nonexistent'));
        self::assertSame('', $response->getHeaderLine('X-Nonexistent'));
    }

    #[Test]
    public function headersAreCaseInsensitive(): void
    {
        $response = new StreamedResponse(
            new ArrayIterator([]),
            headers: ['Content-Type' => 'text/plain'],
        );

        self::assertTrue($response->hasHeader('content-type'));
        self::assertTrue($response->hasHeader('CONTENT-TYPE'));
        self::assertSame('text/plain', $response->getHeaderLine('content-type'));
    }

    #[Test]
    public function transferEncodingCanBeOverridden(): void
    {
        $response = new StreamedResponse(
            new ArrayIterator([]),
            headers: ['Transfer-Encoding' => 'identity'],
        );

        self::assertSame('identity', $response->getHeaderLine('Transfer-Encoding'));
    }

    #[Test]
    public function generatorWithManyChunks(): void
    {
        $response = StreamedResponse::fromGenerator(static function (): Generator {
            for ($i = 0; $i < 1000; $i++) {
                yield "chunk{$i}\n";
            }
        });

        $body = (string) $response->getBody();
        self::assertStringContainsString('chunk0', $body);
        self::assertStringContainsString('chunk999', $body);
    }
}
