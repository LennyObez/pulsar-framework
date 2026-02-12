<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\HttpProtobufTransport;

#[CoversClass(HttpProtobufTransport::class)]
final class HttpProtobufTransportTest extends TestCase
{
    #[Test]
    public function buildHeaderLinesIncludesContentType(): void
    {
        $headers = HttpProtobufTransport::buildHeaderLines([]);

        self::assertSame(['Content-Type: application/x-protobuf'], $headers);
    }

    #[Test]
    public function buildHeaderLinesIncludesCustomHeaders(): void
    {
        $headers = HttpProtobufTransport::buildHeaderLines([
            'Authorization' => 'Bearer token123',
            'X-Custom' => 'value',
        ]);

        self::assertCount(3, $headers);
        self::assertSame('Content-Type: application/x-protobuf', $headers[0]);
        self::assertSame('Authorization: Bearer token123', $headers[1]);
        self::assertSame('X-Custom: value', $headers[2]);
    }

    #[Test]
    public function buildHeaderLinesWithEmptyCustomHeaders(): void
    {
        $headers = HttpProtobufTransport::buildHeaderLines([]);

        self::assertCount(1, $headers);
        self::assertSame('Content-Type: application/x-protobuf', $headers[0]);
    }

    #[Test]
    #[DataProvider('retryableHttpCodeProvider')]
    public function retryableHttpCodeDetection(int $code, bool $expected): void
    {
        self::assertSame($expected, HttpProtobufTransport::isRetryableHttpCode($code));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function retryableHttpCodeProvider(): iterable
    {
        yield 'HTTP 200 not retryable' => [200, false];
        yield 'HTTP 400 not retryable' => [400, false];
        yield 'HTTP 401 not retryable' => [401, false];
        yield 'HTTP 403 not retryable' => [403, false];
        yield 'HTTP 404 not retryable' => [404, false];
        yield 'HTTP 500 not retryable' => [500, false];
        yield 'HTTP 429 retryable' => [429, true];
        yield 'HTTP 502 retryable' => [502, true];
        yield 'HTTP 503 retryable' => [503, true];
        yield 'HTTP 504 retryable' => [504, true];
    }

    #[Test]
    public function buildHeaderLinesPreservesHeaderOrder(): void
    {
        $headers = HttpProtobufTransport::buildHeaderLines([
            'X-First' => 'one',
            'X-Second' => 'two',
            'X-Third' => 'three',
        ]);

        self::assertSame('Content-Type: application/x-protobuf', $headers[0]);
        self::assertSame('X-First: one', $headers[1]);
        self::assertSame('X-Second: two', $headers[2]);
        self::assertSame('X-Third: three', $headers[3]);
    }
}
