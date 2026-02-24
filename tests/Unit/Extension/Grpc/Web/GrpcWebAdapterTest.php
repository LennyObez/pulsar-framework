<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Web\GrpcWebAdapter;
use Pulsar\Extension\Grpc\Web\GrpcWebResponse;

#[CoversClass(GrpcWebAdapter::class)]
final class GrpcWebAdapterTest extends TestCase
{
    private GrpcRequestHandler & Stub $handler;
    private GrpcWebAdapter $adapter;

    protected function setUp(): void
    {
        $this->handler = $this->createStub(GrpcRequestHandler::class);
        $this->adapter = new GrpcWebAdapter($this->handler);
    }

    // --- HTTP method validation ---

    #[Test]
    #[DataProvider('nonPostMethodsProvider')]
    public function rejectsNonPostMethods(string $method): void
    {
        $response = $this->adapter->handle($method, '/pkg.Svc/Method', 'application/grpc-web', '');

        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
        self::assertSame(400, $response->httpStatus);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPostMethodsProvider(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
        yield 'PATCH' => ['PATCH'];
        yield 'OPTIONS' => ['OPTIONS'];
        yield 'HEAD' => ['HEAD'];
    }

    // --- Content type validation ---

    #[Test]
    public function rejectsUnsupportedContentType(): void
    {
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/json',
            '',
        );

        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
        self::assertSame(400, $response->httpStatus);
    }

    #[Test]
    #[DataProvider('validContentTypesProvider')]
    public function acceptsValidGrpcWebContentTypes(string $contentType): void
    {
        $this->handler->method('handle')
            ->willReturn(InterceptorResult::ok('response-data'));

        $binaryFrame = $this->encodeGrpcFrame('hello');
        // grpc-web-text requires base64-encoded body
        $body = $contentType === 'application/grpc-web-text'
            ? base64_encode($binaryFrame)
            : $binaryFrame;
        $response = $this->adapter->handle('POST', '/pkg.Svc/Method', $contentType, $body);

        self::assertSame(GrpcStatus::Ok, $response->status);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validContentTypesProvider(): iterable
    {
        yield 'grpc-web' => ['application/grpc-web'];
        yield 'grpc-web+proto' => ['application/grpc-web+proto'];
        yield 'grpc-web-text' => ['application/grpc-web-text'];
    }

    // --- Request body decoding ---

    #[Test]
    public function rejectsBodyTooShortForFrameHeader(): void
    {
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            "\x00\x00", // Only 2 bytes, needs at least 5
        );

        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    #[Test]
    public function rejectsBodyShorterThanDeclaredLength(): void
    {
        // Frame header claims 100 bytes, but body only has 5 bytes total
        $body = "\x00" . pack('N', 100) . 'short';
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
        );

        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    #[Test]
    public function decodesValidBinaryFrame(): void
    {
        $payload = 'test-payload-data';
        $body = $this->encodeGrpcFrame($payload);

        $this->handler->method('handle')
            ->willReturnCallback(function (string $path, string $receivedPayload) use ($payload): InterceptorResult {
                // Verify the adapter correctly decoded the frame
                self::assertSame($payload, $receivedPayload);

                return InterceptorResult::ok('response');
            });

        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web+proto',
            $body,
        );

        self::assertSame(GrpcStatus::Ok, $response->status);
    }

    #[Test]
    public function decodesBase64EncodedTextBody(): void
    {
        $payload = 'text-payload-data';
        $binaryFrame = $this->encodeGrpcFrame($payload);
        $base64Body = base64_encode($binaryFrame);

        $this->handler->method('handle')
            ->willReturnCallback(function (string $path, string $receivedPayload) use ($payload): InterceptorResult {
                self::assertSame($payload, $receivedPayload);

                return InterceptorResult::ok('response');
            });

        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web-text',
            $base64Body,
        );

        self::assertSame(GrpcStatus::Ok, $response->status);
    }

    #[Test]
    public function rejectsInvalidBase64ForTextEncoding(): void
    {
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web-text',
            '!!!not-valid-base64!!!',
        );

        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    // --- Path and metadata forwarding ---

    #[Test]
    public function forwardsPathToHandler(): void
    {
        $this->handler->method('handle')
            ->willReturnCallback(function (string $path): InterceptorResult {
                self::assertSame('/mypackage.MyService/MyMethod', $path);

                return InterceptorResult::ok('');
            });

        $body = $this->encodeGrpcFrame('data');
        $this->adapter->handle(
            'POST',
            '/mypackage.MyService/MyMethod',
            'application/grpc-web',
            $body,
        );
    }

    #[Test]
    public function convertsHeadersToGrpcMetadata(): void
    {
        $this->handler->method('handle')
            ->willReturnCallback(function (string $path, string $payload, array $metadata): InterceptorResult {
                // Custom headers should be forwarded
                self::assertArrayHasKey('x-custom-header', $metadata);
                self::assertSame(['my-value'], $metadata['x-custom-header']);
                self::assertArrayHasKey('authorization', $metadata);

                return InterceptorResult::ok('');
            });

        $body = $this->encodeGrpcFrame('data');
        $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
            [
                'X-Custom-Header' => 'my-value',
                'Authorization' => 'Bearer token',
            ],
        );
    }

    #[Test]
    #[DataProvider('filteredHeadersProvider')]
    public function filtersOutHttpSpecificHeaders(string $headerName): void
    {
        $this->handler->method('handle')
            ->willReturnCallback(function (string $path, string $payload, array $metadata) use ($headerName): InterceptorResult {
                self::assertArrayNotHasKey(strtolower($headerName), $metadata);

                return InterceptorResult::ok('');
            });

        $body = $this->encodeGrpcFrame('data');
        $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
            [$headerName => 'value'],
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filteredHeadersProvider(): iterable
    {
        yield 'Content-Type' => ['Content-Type'];
        yield 'Content-Length' => ['Content-Length'];
        yield 'Host' => ['Host'];
        yield 'Connection' => ['Connection'];
    }

    // --- Response formatting ---

    #[Test]
    public function buildsSuccessResponseWithDataAndTrailers(): void
    {
        $this->handler->method('handle')
            ->willReturn(new InterceptorResult(
                payload: 'response-data',
                status: GrpcStatus::Ok,
                message: '',
                trailers: ['x-trace-id' => ['abc123']],
            ));

        $body = $this->encodeGrpcFrame('request');
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web+proto',
            $body,
        );

        self::assertSame(GrpcStatus::Ok, $response->status);
        self::assertSame(200, $response->httpStatus);
        self::assertSame('application/grpc-web+proto', $response->contentType);

        // Response body should contain data frame + trailer frame
        $responseBody = $response->body;

        // First byte should be data frame flag (0x00)
        self::assertSame(0, ord($responseBody[0]));

        // Extract data frame length
        $unpacked = unpack('Nlength', $responseBody, 1);
        assert($unpacked !== false);
        $dataLen = $unpacked['length'];
        self::assertSame(strlen('response-data'), $dataLen);

        // After data frame, trailer frame starts with flag 0x80
        $trailerOffset = 5 + $dataLen;
        self::assertSame(0x80, ord($responseBody[$trailerOffset]));
    }

    #[Test]
    public function buildsErrorResponseWithGrpcMessage(): void
    {
        $this->handler->method('handle')
            ->willReturn(InterceptorResult::error(
                GrpcStatus::NotFound,
                'Resource not found',
            ));

        $body = $this->encodeGrpcFrame('request');
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
        );

        self::assertSame(GrpcStatus::NotFound, $response->status);
        self::assertSame(404, $response->httpStatus);

        // Response body should contain the grpc-status and grpc-message in trailers
        self::assertStringContainsString('grpc-status:5', $response->body);
        self::assertStringContainsString('grpc-message:Resource not found', $response->body);
    }

    #[Test]
    public function textEncodedResponseIsBase64(): void
    {
        $this->handler->method('handle')
            ->willReturn(InterceptorResult::ok('text-response'));

        $binaryFrame = $this->encodeGrpcFrame('request');
        $base64Body = base64_encode($binaryFrame);

        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web-text',
            $base64Body,
        );

        self::assertSame('application/grpc-web-text', $response->contentType);
        // Response body should be valid base64
        $decoded = base64_decode($response->body, true);
        self::assertNotFalse($decoded);
        // And it should start with a data frame
        self::assertSame(0, ord($decoded[0]));
    }

    #[Test]
    public function emptyPayloadInResultProducesValidResponse(): void
    {
        $this->handler->method('handle')
            ->willReturn(InterceptorResult::ok(''));

        $body = $this->encodeGrpcFrame('request');
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
        );

        self::assertSame(GrpcStatus::Ok, $response->status);
        self::assertSame(200, $response->httpStatus);

        // Data frame with 0-length payload
        self::assertSame(0, ord($response->body[0]));
        $unpacked = unpack('Nlength', $response->body, 1);
        assert($unpacked !== false);
        $dataLen = $unpacked['length'];
        self::assertSame(0, $dataLen);
    }

    #[Test]
    public function responseIncludesCustomTrailers(): void
    {
        $this->handler->method('handle')
            ->willReturn(new InterceptorResult(
                payload: '',
                status: GrpcStatus::Ok,
                message: '',
                trailers: [
                    'x-custom-a' => ['val1', 'val2'],
                    'x-custom-b' => ['val3'],
                ],
            ));

        $body = $this->encodeGrpcFrame('request');
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
        );

        self::assertStringContainsString('x-custom-a:val1', $response->body);
        self::assertStringContainsString('x-custom-a:val2', $response->body);
        self::assertStringContainsString('x-custom-b:val3', $response->body);
    }

    #[Test]
    public function emptyHeadersArrayProducesEmptyMetadata(): void
    {
        $this->handler->method('handle')
            ->willReturnCallback(function (string $path, string $payload, array $metadata): InterceptorResult {
                self::assertSame([], $metadata);

                return InterceptorResult::ok('');
            });

        $body = $this->encodeGrpcFrame('data');
        $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $body,
            [],
        );
    }

    #[Test]
    public function emptyBodyForBinaryContentTypeReturnsDeclareError(): void
    {
        $response = $this->adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            '', // Empty body
        );

        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    // --- Helper methods ---

    /**
     * Encode data as a gRPC frame: 1 byte flags + 4 bytes big-endian length + data.
     */
    /**
     * @param int<0, 255> $flags
     */
    private function encodeGrpcFrame(string $data, int $flags = 0): string
    {
        return chr($flags) . pack('N', strlen($data)) . $data;
    }
}
