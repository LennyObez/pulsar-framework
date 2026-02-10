<?php

declare(strict_types=1);

namespace Pulsar\Tests\Extension\Grpc\Unit\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Adapter\GrpcRequestHandler;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Interceptor\InterceptorResult;
use Pulsar\Extension\Grpc\Web\GrpcWebAdapter;
use Pulsar\Extension\Grpc\Web\GrpcWebContentType;
use Pulsar\Extension\Grpc\Web\GrpcWebResponse;

use function chr;
use function ord;
use function strlen;

#[CoversClass(GrpcWebAdapter::class)]
#[CoversClass(GrpcWebContentType::class)]
#[CoversClass(GrpcWebResponse::class)]
final class GrpcWebAdapterTest extends TestCase
{
    #[Test]
    public function rejectsNonPostMethod(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $adapter = new GrpcWebAdapter($handler);

        $response = $adapter->handle('GET', '/pkg.Svc/Method', 'application/grpc-web', '');

        self::assertFalse($response->isOk());
        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    #[Test]
    public function rejectsUnsupportedContentType(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $adapter = new GrpcWebAdapter($handler);

        $response = $adapter->handle('POST', '/pkg.Svc/Method', 'application/json', '');

        self::assertFalse($response->isOk());
        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    #[Test]
    public function rejectsTooShortBody(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $adapter = new GrpcWebAdapter($handler);

        $response = $adapter->handle('POST', '/pkg.Svc/Method', 'application/grpc-web', 'abc');

        self::assertFalse($response->isOk());
        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }

    #[Test]
    public function handlesUnaryCallSuccessfully(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $handler->method('handle')->willReturn(InterceptorResult::ok('response-payload'));

        $adapter = new GrpcWebAdapter($handler);

        // Build a gRPC-Web frame: flag(0) + length(4 bytes) + data
        $payload = 'test';
        $frame = chr(0) . pack('N', strlen($payload)) . $payload;

        $response = $adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web+proto',
            $frame,
        );

        self::assertTrue($response->isOk());
        self::assertSame(200, $response->httpStatus);
        self::assertSame('application/grpc-web+proto', $response->contentType);
        self::assertNotEmpty($response->body);
    }

    #[Test]
    public function handlesErrorFromBackend(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $handler->method('handle')->willReturn(
            InterceptorResult::error(GrpcStatus::NotFound, 'Service not found'),
        );

        $adapter = new GrpcWebAdapter($handler);

        $payload = 'test';
        $frame = chr(0) . pack('N', strlen($payload)) . $payload;

        $response = $adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $frame,
        );

        self::assertFalse($response->isOk());
        self::assertSame(GrpcStatus::NotFound, $response->status);
    }

    #[Test]
    public function handlesGrpcWebTextWithBase64(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $handler->method('handle')->willReturn(InterceptorResult::ok('reply'));

        $adapter = new GrpcWebAdapter($handler);

        // Build a gRPC-Web frame and base64 encode it
        $payload = 'hello';
        $frame = chr(0) . pack('N', strlen($payload)) . $payload;
        $base64Frame = base64_encode($frame);

        $response = $adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web-text',
            $base64Frame,
        );

        self::assertTrue($response->isOk());
        // Text encoding response should also be base64
        self::assertNotEmpty($response->body);
    }

    #[Test]
    public function responseBodyContainsDataAndTrailerFrames(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $handler->method('handle')->willReturn(InterceptorResult::ok('data'));

        $adapter = new GrpcWebAdapter($handler);

        $payload = 'req';
        $frame = chr(0) . pack('N', strlen($payload)) . $payload;

        $response = $adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web+proto',
            $frame,
        );

        $body = $response->body;

        // First frame should be data frame (flag = 0)
        self::assertSame(0, ord($body[0]));

        // Find the trailer frame (flag = 0x80)
        $dataLength = unpack('N', substr($body, 1, 4));
        self::assertIsArray($dataLength);
        $trailerStart = 5 + $dataLength[1];

        self::assertGreaterThan($trailerStart, strlen($body));
        self::assertSame(0x80, ord($body[$trailerStart]));
    }

    #[Test]
    public function forwardsMetadataFromHeaders(): void
    {
        $handler = $this->createMock(GrpcRequestHandler::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(
                '/pkg.Svc/Method',
                self::anything(),
                self::callback(static function (array $metadata): bool {
                    return isset($metadata['authorization']);
                }),
            )
            ->willReturn(InterceptorResult::ok(''));

        $adapter = new GrpcWebAdapter($handler);

        $payload = 'req';
        $frame = chr(0) . pack('N', strlen($payload)) . $payload;

        $adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web',
            $frame,
            ['Authorization' => 'Bearer token123'],
        );
    }

    #[Test]
    public function grpcWebContentTypeIsSupported(): void
    {
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web'));
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web+proto'));
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web-text'));
        self::assertFalse(GrpcWebContentType::isSupported('application/json'));
        self::assertFalse(GrpcWebContentType::isSupported('text/html'));
    }

    #[Test]
    public function grpcWebContentTypeFromHeader(): void
    {
        self::assertSame(
            GrpcWebContentType::GrpcWeb,
            GrpcWebContentType::fromHeader('application/grpc-web'),
        );
        self::assertSame(
            GrpcWebContentType::GrpcWebProto,
            GrpcWebContentType::fromHeader('application/grpc-web+proto'),
        );
        self::assertSame(
            GrpcWebContentType::GrpcWebText,
            GrpcWebContentType::fromHeader('application/grpc-web-text'),
        );
        self::assertNull(GrpcWebContentType::fromHeader('text/plain'));
    }

    #[Test]
    public function grpcWebContentTypeFromHeaderWithParameters(): void
    {
        self::assertSame(
            GrpcWebContentType::GrpcWeb,
            GrpcWebContentType::fromHeader('application/grpc-web; charset=utf-8'),
        );
    }

    #[Test]
    public function grpcWebContentTypeTextEncoding(): void
    {
        self::assertTrue(GrpcWebContentType::GrpcWebText->isTextEncoded());
        self::assertFalse(GrpcWebContentType::GrpcWeb->isTextEncoded());
        self::assertFalse(GrpcWebContentType::GrpcWebProto->isTextEncoded());
    }

    #[Test]
    public function grpcWebContentTypeResponseContentType(): void
    {
        self::assertSame('application/grpc-web+proto', GrpcWebContentType::GrpcWeb->responseContentType());
        self::assertSame('application/grpc-web+proto', GrpcWebContentType::GrpcWebProto->responseContentType());
        self::assertSame('application/grpc-web-text', GrpcWebContentType::GrpcWebText->responseContentType());
    }

    #[Test]
    public function errorResponseIncludesStatusInBody(): void
    {
        $response = GrpcWebResponse::error(GrpcStatus::Internal, 'Something broke');

        self::assertFalse($response->isOk());
        self::assertSame(GrpcStatus::Internal, $response->status);
        self::assertSame(500, $response->httpStatus);

        // Body should contain trailer frame with grpc-status
        self::assertSame(0x80, ord($response->body[0]));
    }

    #[Test]
    public function responseHeadersIncludeContentType(): void
    {
        $response = new GrpcWebResponse(
            body: '',
            status: GrpcStatus::Ok,
            contentType: 'application/grpc-web+proto',
        );

        $headers = $response->headers();

        self::assertSame('application/grpc-web+proto', $headers['Content-Type']);
        self::assertSame('true', $headers['X-Grpc-Web']);
    }

    #[Test]
    public function rejectsInvalidBase64ForTextEncoding(): void
    {
        $handler = $this->createStub(GrpcRequestHandler::class);
        $adapter = new GrpcWebAdapter($handler);

        // Invalid base64 that decodes to less than 5 bytes
        $response = $adapter->handle(
            'POST',
            '/pkg.Svc/Method',
            'application/grpc-web-text',
            '!!!invalid-base64!!!',
        );

        self::assertFalse($response->isOk());
        self::assertSame(GrpcStatus::InvalidArgument, $response->status);
    }
}
