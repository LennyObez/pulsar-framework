<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Export\Otlp\Transport;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Export\Otlp\Transport\GrpcTransport;

use function strlen;

#[CoversClass(GrpcTransport::class)]
final class GrpcTransportTest extends TestCase
{
    #[Test]
    public function constructorRejectsInvalidScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('scheme must be http or https');

        new GrpcTransport('ftp://collector.example.com:4317');
    }

    #[Test]
    public function constructorRejectsMalformedUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GrpcTransport('http://');
    }

    #[Test]
    public function constructorAcceptsHttpEndpoint(): void
    {
        $transport = new GrpcTransport('http://collector.local:4317');

        self::assertInstanceOf(GrpcTransport::class, $transport);
    }

    #[Test]
    public function constructorAcceptsHttpsEndpoint(): void
    {
        $transport = new GrpcTransport('https://collector.example.com:4317');

        self::assertInstanceOf(GrpcTransport::class, $transport);
    }

    #[Test]
    public function buildGrpcFrameProducesCorrectFormat(): void
    {
        $payload = 'test-payload';
        $frame = GrpcTransport::buildGrpcFrame($payload);

        // First byte: compressed flag (0x00)
        self::assertSame("\x00", $frame[0]);

        // Next 4 bytes: big-endian length
        $length = unpack('N', substr($frame, 1, 4));
        self::assertIsArray($length);
        self::assertSame(strlen($payload), $length[1]);

        // Remaining bytes: payload
        self::assertSame($payload, substr($frame, 5));
    }

    #[Test]
    public function buildGrpcFrameWithEmptyPayload(): void
    {
        $frame = GrpcTransport::buildGrpcFrame('');

        self::assertSame(5, strlen($frame));
        self::assertSame("\x00", $frame[0]);

        $length = unpack('N', substr($frame, 1, 4));
        self::assertIsArray($length);
        self::assertSame(0, $length[1]);
    }

    /**
     * @param array{key: string, value: string}|null $expected
     */
    #[Test]
    #[DataProvider('grpcHeaderProvider')]
    public function parseGrpcHeaderExtractsKnownHeaders(string $headerLine, ?array $expected): void
    {
        $result = GrpcTransport::parseGrpcHeader($headerLine);

        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, array{key: string, value: string}|null}>
     */
    public static function grpcHeaderProvider(): iterable
    {
        yield 'grpc-status ok' => [
            'grpc-status: 0',
            ['key' => 'grpc-status', 'value' => '0'],
        ];

        yield 'grpc-status error' => [
            'grpc-status: 14',
            ['key' => 'grpc-status', 'value' => '14'],
        ];

        yield 'grpc-message' => [
            'grpc-message: Service unavailable',
            ['key' => 'grpc-message', 'value' => 'Service unavailable'],
        ];

        yield 'grpc-status with whitespace' => [
            "  grpc-status : 0  \r\n",
            ['key' => 'grpc-status', 'value' => '0'],
        ];

        yield 'case insensitive' => [
            'GRPC-Status: 0',
            ['key' => 'grpc-status', 'value' => '0'],
        ];

        yield 'non-grpc header' => [
            'Content-Type: application/grpc',
            null,
        ];

        yield 'empty line' => [
            '',
            null,
        ];
    }

    #[Test]
    #[DataProvider('retryableGrpcCodeProvider')]
    public function isRetryableGrpcCodeClassifiesCorrectly(int $code, bool $expected): void
    {
        self::assertSame($expected, GrpcTransport::isRetryableGrpcCode($code));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function retryableGrpcCodeProvider(): iterable
    {
        yield 'OK (0) not retryable' => [0, false];
        yield 'CANCELLED (1) not retryable' => [1, false];
        yield 'DEADLINE_EXCEEDED (4) retryable' => [4, true];
        yield 'RESOURCE_EXHAUSTED (8) retryable' => [8, true];
        yield 'UNAVAILABLE (14) retryable' => [14, true];
        yield 'INTERNAL (13) not retryable' => [13, false];
        yield 'UNIMPLEMENTED (12) not retryable' => [12, false];
    }

    #[Test]
    public function buildHeaderLinesIncludesGrpcDefaults(): void
    {
        $headers = GrpcTransport::buildHeaderLines([]);

        self::assertContains('content-type: application/grpc', $headers);
        self::assertContains('te: trailers', $headers);
        self::assertCount(2, $headers);
    }

    #[Test]
    public function buildHeaderLinesAppendsCustomHeaders(): void
    {
        $headers = GrpcTransport::buildHeaderLines([
            'Authorization' => 'Bearer token123',
            'X-Custom' => 'value',
        ]);

        self::assertContains('content-type: application/grpc', $headers);
        self::assertContains('te: trailers', $headers);
        self::assertContains('Authorization: Bearer token123', $headers);
        self::assertContains('X-Custom: value', $headers);
        self::assertCount(4, $headers);
    }
}
