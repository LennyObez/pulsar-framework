<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\OpenApi\Attribute;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\OpenApi\Attribute\ApiDoc;

#[CoversClass(ApiDoc::class)]
final class ApiDocTest extends TestCase
{
    #[Test]
    public function constructs_with_defaults(): void
    {
        $doc = new ApiDoc();

        self::assertSame('', $doc->summary);
        self::assertSame('', $doc->description);
        self::assertSame([], $doc->tags);
        self::assertFalse($doc->deprecated);
        self::assertNull($doc->operationId);
    }

    #[Test]
    public function constructs_with_all_fields(): void
    {
        $doc = new ApiDoc(
            summary: 'List users',
            description: 'Returns paginated list of **all** users',
            tags: ['users', 'admin'],
            deprecated: true,
            operationId: 'listUsers',
        );

        self::assertSame('List users', $doc->summary);
        self::assertSame('Returns paginated list of **all** users', $doc->description);
        self::assertSame(['users', 'admin'], $doc->tags);
        self::assertTrue($doc->deprecated);
        self::assertSame('listUsers', $doc->operationId);
    }

    #[Test]
    public function summary_only(): void
    {
        $doc = new ApiDoc(summary: 'Get user by ID');

        self::assertSame('Get user by ID', $doc->summary);
        self::assertSame('', $doc->description);
        self::assertSame([], $doc->tags);
        self::assertFalse($doc->deprecated);
    }
}
