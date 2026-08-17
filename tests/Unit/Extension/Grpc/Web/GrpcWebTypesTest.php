<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Error\GrpcStatus;
use Pulsar\Extension\Grpc\Web\GrpcWebContentType;
use Pulsar\Extension\Grpc\Web\GrpcWebResponse;

#[CoversClass(GrpcWebResponse::class)]
final class GrpcWebTypesTest extends TestCase
{
    // --- GrpcWebContentType ---

    #[Test]
    public function isSupportedForKnownContentTypes(): void
    {
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web'));
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web+proto'));
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web-text'));
    }

    #[Test]
    public function isSupportedIsCaseInsensitive(): void
    {
        self::assertTrue(GrpcWebContentType::isSupported('Application/gRPC-Web'));
    }

    #[Test]
    public function isSupportedForGrpcWebPrefix(): void
    {
        self::assertTrue(GrpcWebContentType::isSupported('application/grpc-web+json'));
    }

    #[Test]
    public function isSupportedReturnsFalseForUnrelatedTypes(): void
    {
        self::assertFalse(GrpcWebContentType::isSupported('application/json'));
        self::assertFalse(GrpcWebContentType::isSupported('text/html'));
    }

    #[Test]
    public function fromHeaderExactMatch(): void
    {
        self::assertSame(GrpcWebContentType::GrpcWeb, GrpcWebContentType::fromHeader('application/grpc-web'));
        self::assertSame(GrpcWebContentType::GrpcWebProto, GrpcWebContentType::fromHeader('application/grpc-web+proto'));
        self::assertSame(GrpcWebContentType::GrpcWebText, GrpcWebContentType::fromHeader('application/grpc-web-text'));
    }

    #[Test]
    public function fromHeaderWithParameters(): void
    {
        $result = GrpcWebContentType::fromHeader('application/grpc-web+proto; charset=utf-8');
        self::assertSame(GrpcWebContentType::GrpcWebProto, $result);
    }

    #[Test]
    public function fromHeaderReturnsNullForUnknown(): void
    {
        self::assertNull(GrpcWebContentType::fromHeader('application/json'));
    }

    #[Test]
    public function isTextEncoded(): void
    {
        self::assertFalse(GrpcWebContentType::GrpcWeb->isTextEncoded());
        self::assertFalse(GrpcWebContentType::GrpcWebProto->isTextEncoded());
        self::assertTrue(GrpcWebContentType::GrpcWebText->isTextEncoded());
    }

    // --- GrpcWebResponse ---

    #[Test]
    public function constructionWithDefaults(): void
    {
        $response = new GrpcWebResponse(body: 'data', status: GrpcStatus::Ok);

        self::assertSame('data', $response->body);
        self::assertSame(GrpcStatus::Ok, $response->status);
        self::assertSame('application/grpc-web+proto', $response->contentType);
        self::assertSame(200, $response->httpStatus);
    }

    #[Test]
    public function errorFactoryBuildsTrailersOnlyResponse(): void
    {
        $response = GrpcWebResponse::error(GrpcStatus::NotFound, 'Resource not found');

        self::assertSame(GrpcStatus::NotFound, $response->status);
        self::assertSame(404, $response->httpStatus);
        self::assertStringContainsString('grpc-status:5', $response->body);
        self::assertStringContainsString('grpc-message:Resource not found', $response->body);
    }

    #[Test]
    public function errorFactoryWithoutMessage(): void
    {
        $response = GrpcWebResponse::error(GrpcStatus::Internal);

        self::assertSame(GrpcStatus::Internal, $response->status);
        self::assertSame(500, $response->httpStatus);
        self::assertStringContainsString('grpc-status:13', $response->body);
        self::assertStringNotContainsString('grpc-message:', $response->body);
    }

    #[Test]
    public function isOk(): void
    {
        self::assertTrue(new GrpcWebResponse('', GrpcStatus::Ok)->isOk());
        self::assertFalse(GrpcWebResponse::error(GrpcStatus::Internal)->isOk());
    }
}
