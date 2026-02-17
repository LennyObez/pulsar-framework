<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\VectorStore;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Orm\Attribute\Vector;
use ReflectionClass;

#[CoversClass(Vector::class)]
final class VectorAttributeTest extends TestCase
{
    #[Test]
    public function defaultDimensionsIs1536(): void
    {
        $attr = new Vector();

        self::assertSame(1536, $attr->dimensions);
        self::assertNull($attr->name);
        self::assertSame('cosine', $attr->distanceMetric);
    }

    #[Test]
    public function customDimensionsAndMetric(): void
    {
        $attr = new Vector(
            dimensions: 3072,
            name: 'embedding_col',
            distanceMetric: 'l2',
        );

        self::assertSame(3072, $attr->dimensions);
        self::assertSame('embedding_col', $attr->name);
        self::assertSame('l2', $attr->distanceMetric);
    }

    #[Test]
    public function attributeTargetsPropertyOnly(): void
    {
        $ref = new ReflectionClass(Vector::class);
        $attrs = $ref->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        /** @var Attribute $attrInstance */
        $attrInstance = $attrs[0]->newInstance();
        self::assertSame(Attribute::TARGET_PROPERTY, $attrInstance->flags);
    }
}
