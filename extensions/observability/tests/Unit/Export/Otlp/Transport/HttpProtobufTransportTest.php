<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Transport;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Transport\HttpProtobufTransport;

#[CoversClass(HttpProtobufTransport::class)]
final class HttpProtobufTransportTest extends TestCase
{
    #[Test]
    public function constructorRejectsInvalidScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('scheme must be http or https');

        new HttpProtobufTransport('ftp://collector.example.com:4318');
    }

    #[Test]
    public function constructorRejectsMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpProtobufTransport('http://');
    }

    #[Test]
    public function constructorAcceptsHttpEndpoint(): void
    {
        $transport = new HttpProtobufTransport('http://collector.local:4318');

        self::assertInstanceOf(HttpProtobufTransport::class, $transport);
    }

    #[Test]
    public function constructorAcceptsHttpsEndpoint(): void
    {
        $transport = new HttpProtobufTransport('https://collector.example.com:4318');

        self::assertInstanceOf(HttpProtobufTransport::class, $transport);
    }

    #[Test]
    public function buildHeaderLinesIncludesProtobufContentType(): void
    {
        $headers = HttpProtobufTransport::buildHeaderLines([]);

        self::assertContains('Content-Type: application/x-protobuf', $headers);
        self::assertCount(1, $headers);
    }

    #[Test]
    public function buildHeaderLinesAppendsCustomHeaders(): void
    {
        $headers = HttpProtobufTransport::buildHeaderLines([
            'Authorization' => 'Bearer abc',
            'X-Tenant' => 'org-123',
        ]);

        self::assertContains('Content-Type: application/x-protobuf', $headers);
        self::assertContains('Authorization: Bearer abc', $headers);
        self::assertContains('X-Tenant: org-123', $headers);
        self::assertCount(3, $headers);
    }

    #[Test]
    #[DataProvider('retryableHttpCodeProvider')]
    public function isRetryableHttpCodeClassifiesCorrectly(int $code, bool $expected): void
    {
        self::assertSame($expected, HttpProtobufTransport::isRetryableHttpCode($code));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function retryableHttpCodeProvider(): iterable
    {
        yield '200 not retryable' => [200, false];
        yield '400 not retryable' => [400, false];
        yield '401 not retryable' => [401, false];
        yield '403 not retryable' => [403, false];
        yield '429 retryable' => [429, true];
        yield '500 not retryable' => [500, false];
        yield '502 retryable' => [502, true];
        yield '503 retryable' => [503, true];
        yield '504 retryable' => [504, true];
    }
}
