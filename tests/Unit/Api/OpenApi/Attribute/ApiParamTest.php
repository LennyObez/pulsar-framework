<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\Attribute\ApiParam;

#[CoversClass(ApiParam::class)]
final class ApiParamTest extends TestCase
{
    #[Test]
    public function constructs_with_name_only(): void
    {
        $param = new ApiParam(name: 'id');

        self::assertSame('id', $param->name);
        self::assertSame('query', $param->in);
        self::assertSame('string', $param->type);
        self::assertFalse($param->required);
        self::assertSame('', $param->description);
        self::assertNull($param->format);
        self::assertNull($param->example);
        self::assertNull($param->default);
        self::assertNull($param->enum);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $param = new ApiParam(
            name: 'user_id',
            in: 'path',
            type: 'integer',
            required: true,
            description: 'The user identifier',
            format: 'int64',
            example: 42,
            default: 0,
            enum: [1, 2, 3],
        );

        self::assertSame('user_id', $param->name);
        self::assertSame('path', $param->in);
        self::assertSame('integer', $param->type);
        self::assertTrue($param->required);
        self::assertSame('The user identifier', $param->description);
        self::assertSame('int64', $param->format);
        self::assertSame(42, $param->example);
        self::assertSame(0, $param->default);
        self::assertSame([1, 2, 3], $param->enum);
    }

    #[Test]
    public function header_parameter(): void
    {
        $param = new ApiParam(
            name: 'X-Request-ID',
            in: 'header',
            format: 'uuid',
        );

        self::assertSame('X-Request-ID', $param->name);
        self::assertSame('header', $param->in);
        self::assertSame('uuid', $param->format);
    }

    #[Test]
    public function string_enum(): void
    {
        $param = new ApiParam(
            name: 'status',
            enum: ['active', 'inactive', 'pending'],
        );

        self::assertSame(['active', 'inactive', 'pending'], $param->enum);
    }
}
