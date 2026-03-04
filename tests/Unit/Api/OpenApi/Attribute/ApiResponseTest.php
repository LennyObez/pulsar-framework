<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\Attribute\ApiResponse;
use stdClass;

#[CoversClass(ApiResponse::class)]
final class ApiResponseTest extends TestCase
{
    #[Test]
    public function constructs_with_defaults(): void
    {
        $response = new ApiResponse();

        self::assertSame(200, $response->status);
        self::assertSame('', $response->description);
        self::assertNull($response->schema);
        self::assertFalse($response->isCollection);
        self::assertSame('application/json', $response->mediaType);
        self::assertNull($response->headers);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $response = new ApiResponse(
            status: 201,
            description: 'User created successfully',
            schema: stdClass::class,
            isCollection: false,
            mediaType: 'application/json',
            headers: ['Location' => 'URI of the created resource'],
        );

        self::assertSame(201, $response->status);
        self::assertSame('User created successfully', $response->description);
        self::assertSame(stdClass::class, $response->schema);
        self::assertFalse($response->isCollection);
        self::assertSame(['Location' => 'URI of the created resource'], $response->headers);
    }

    #[Test]
    public function collection_response(): void
    {
        $response = new ApiResponse(
            status: 200,
            description: 'List of users',
            schema: stdClass::class,
            isCollection: true,
        );

        self::assertTrue($response->isCollection);
    }

    #[Test]
    public function error_response(): void
    {
        $response = new ApiResponse(
            status: 404,
            description: 'Resource not found',
        );

        self::assertSame(404, $response->status);
        self::assertNull($response->schema);
    }

    #[Test]
    public function custom_media_type(): void
    {
        $response = new ApiResponse(
            status: 200,
            mediaType: 'text/csv',
        );

        self::assertSame('text/csv', $response->mediaType);
    }
}
