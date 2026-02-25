<?php

declare(strict_types=1);

namespace Pulsar\Extension\Graphql\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Content\ContentType;
use Pulsar\Extension\Graphql\Schema\TypeMapper;

final class TypeMapperTest extends TestCase
{
    #[Test]
    public function mapFieldTypeReturnsIntForInteger(): void
    {
        self::assertSame('Int', TypeMapper::mapFieldType('int'));
        self::assertSame('Int', TypeMapper::mapFieldType('integer'));
    }

    #[Test]
    public function mapFieldTypeReturnsFloatForFloatTypes(): void
    {
        self::assertSame('Float', TypeMapper::mapFieldType('float'));
        self::assertSame('Float', TypeMapper::mapFieldType('double'));
        self::assertSame('Float', TypeMapper::mapFieldType('decimal'));
    }

    #[Test]
    public function mapFieldTypeReturnsBooleanForBoolTypes(): void
    {
        self::assertSame('Boolean', TypeMapper::mapFieldType('bool'));
        self::assertSame('Boolean', TypeMapper::mapFieldType('boolean'));
    }

    #[Test]
    public function mapFieldTypeReturnsStringForUnknownTypes(): void
    {
        self::assertSame('String', TypeMapper::mapFieldType('varchar'));
        self::assertSame('String', TypeMapper::mapFieldType('text'));
        self::assertSame('String', TypeMapper::mapFieldType('unknown'));
    }

    #[Test]
    public function mapContentTypeReturnsArticle(): void
    {
        self::assertSame('Article', TypeMapper::mapContentType(ContentType::Article));
    }

    #[Test]
    public function mapContentTypeReturnsPage(): void
    {
        self::assertSame('Page', TypeMapper::mapContentType(ContentType::Page));
    }
}
