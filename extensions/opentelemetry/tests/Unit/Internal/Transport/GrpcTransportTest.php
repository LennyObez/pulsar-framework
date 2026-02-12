<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\GrpcTransport;

use function str_repeat;
use function strlen;
use function substr;
use function unpack;

#[CoversClass(GrpcTransport::class)]
final class GrpcTransportTest extends TestCase
{
    #[Test]
    public function buildGrpcFrameHasCorrectPrefix(): void
    {
        $payload = 'test-payload';
        $frame = GrpcTransport::buildGrpcFrame($payload);

        // First byte: compression flag (0x00 = uncompressed)
        self::assertSame("\x00", $frame[0]);
    }

    #[Test]
    public function buildGrpcFrameEncodesLengthAsBigEndian(): void
    {
        $payload = 'test-payload';
        $frame = GrpcTransport::buildGrpcFrame($payload);

        // Bytes 1-4: big-endian uint32 payload length
        $lengthBytes = substr($frame, 1, 4);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', $lengthBytes);
        self::assertSame(strlen($payload), $unpacked[1]);
    }

    #[Test]
    public function buildGrpcFrameAppendsPayload(): void
    {
        $payload = 'test-payload';
        $frame = GrpcTransport::buildGrpcFrame($payload);

        // Bytes 5+: the actual payload
        self::assertSame($payload, substr($frame, 5));
    }

    #[Test]
    public function buildGrpcFrameTotalLength(): void
    {
        $payload = 'hello';
        $frame = GrpcTransport::buildGrpcFrame($payload);

        // 1 byte flag + 4 bytes length + payload length
        self::assertSame(1 + 4 + strlen($payload), strlen($frame));
    }

    #[Test]
    public function buildGrpcFrameWithEmptyPayload(): void
    {
        $frame = GrpcTransport::buildGrpcFrame('');

        self::assertSame(5, strlen($frame));
        self::assertSame("\x00", $frame[0]);
        self::assertSame("\x00\x00\x00\x00", substr($frame, 1, 4));
    }

    #[Test]
    public function buildGrpcFrameWithLargePayload(): void
    {
        $payload = str_repeat('A', 65536);
        $frame = GrpcTransport::buildGrpcFrame($payload);

        self::assertSame(5 + 65536, strlen($frame));

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($frame, 1, 4));
        self::assertSame(65536, $unpacked[1]);
        self::assertSame($payload, substr($frame, 5));
    }

    #[Test]
    public function parseGrpcHeaderExtractsStatus(): void
    {
        $result = GrpcTransport::parseGrpcHeader('grpc-status: 0');

        self::assertNotNull($result);
        self::assertSame('grpc-status', $result['key']);
        self::assertSame('0', $result['value']);
    }

    #[Test]
    public function parseGrpcHeaderExtractsNonZeroStatus(): void
    {
        $result = GrpcTransport::parseGrpcHeader('grpc-status: 14');

        self::assertNotNull($result);
        self::assertSame('grpc-status', $result['key']);
        self::assertSame('14', $result['value']);
    }

    #[Test]
    public function parseGrpcHeaderExtractsMessage(): void
    {
        $result = GrpcTransport::parseGrpcHeader('grpc-message: Service unavailable');

        self::assertNotNull($result);
        self::assertSame('grpc-message', $result['key']);
        self::assertSame('Service unavailable', $result['value']);
    }

    #[Test]
    public function parseGrpcHeaderIsCaseInsensitive(): void
    {
        $result = GrpcTransport::parseGrpcHeader('Grpc-Status: 8');

        self::assertNotNull($result);
        self::assertSame('grpc-status', $result['key']);
        self::assertSame('8', $result['value']);
    }

    #[Test]
    public function parseGrpcHeaderTrimsWhitespace(): void
    {
        $result = GrpcTransport::parseGrpcHeader("  grpc-status:  0  \r\n");

        self::assertNotNull($result);
        self::assertSame('grpc-status', $result['key']);
        self::assertSame('0', $result['value']);
    }

    #[Test]
    public function parseGrpcHeaderReturnsNullForNonGrpcHeader(): void
    {
        self::assertNull(GrpcTransport::parseGrpcHeader('content-type: application/grpc'));
        self::assertNull(GrpcTransport::parseGrpcHeader('HTTP/2 200'));
        self::assertNull(GrpcTransport::parseGrpcHeader(''));
        self::assertNull(GrpcTransport::parseGrpcHeader('x-custom: value'));
    }

    #[Test]
    #[DataProvider('retryableGrpcCodeProvider')]
    public function retryableGrpcCodeDetection(int $code, bool $expected): void
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
        yield 'UNKNOWN (2) not retryable' => [2, false];
        yield 'INVALID_ARGUMENT (3) not retryable' => [3, false];
        yield 'DEADLINE_EXCEEDED (4) retryable' => [4, true];
        yield 'NOT_FOUND (5) not retryable' => [5, false];
        yield 'ALREADY_EXISTS (6) not retryable' => [6, false];
        yield 'PERMISSION_DENIED (7) not retryable' => [7, false];
        yield 'RESOURCE_EXHAUSTED (8) retryable' => [8, true];
        yield 'FAILED_PRECONDITION (9) not retryable' => [9, false];
        yield 'ABORTED (10) not retryable' => [10, false];
        yield 'OUT_OF_RANGE (11) not retryable' => [11, false];
        yield 'UNIMPLEMENTED (12) not retryable' => [12, false];
        yield 'INTERNAL (13) not retryable' => [13, false];
        yield 'UNAVAILABLE (14) retryable' => [14, true];
        yield 'DATA_LOSS (15) not retryable' => [15, false];
        yield 'UNAUTHENTICATED (16) not retryable' => [16, false];
    }

    #[Test]
    public function buildHeaderLinesIncludesGrpcHeaders(): void
    {
        $headers = GrpcTransport::buildHeaderLines([]);

        self::assertCount(2, $headers);
        self::assertSame('content-type: application/grpc', $headers[0]);
        self::assertSame('te: trailers', $headers[1]);
    }

    #[Test]
    public function buildHeaderLinesIncludesCustomHeaders(): void
    {
        $headers = GrpcTransport::buildHeaderLines([
            'authorization' => 'Bearer token',
            'x-tenant-id' => 'tenant-42',
        ]);

        self::assertCount(4, $headers);
        self::assertSame('content-type: application/grpc', $headers[0]);
        self::assertSame('te: trailers', $headers[1]);
        self::assertSame('authorization: Bearer token', $headers[2]);
        self::assertSame('x-tenant-id: tenant-42', $headers[3]);
    }
}
