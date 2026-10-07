<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ProductAttribute;

#[CoversClass(ProductAttribute::class)]
final class ProductAttributeTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $attr = new ProductAttribute(
            id: 'attr-001',
            productId: 'prod-001',
            attributeKey: 'color',
            allowedValues: ['red', 'blue', 'green'],
            translations: ['en' => ['red' => 'Red', 'blue' => 'Blue']],
        );

        self::assertSame('attr-001', $attr->id);
        self::assertSame('prod-001', $attr->productId);
        self::assertSame('color', $attr->attributeKey);
        self::assertSame(['red', 'blue', 'green'], $attr->allowedValues);
        self::assertSame('Red', $attr->translations['en']['red']);
    }

    #[Test]
    public function emptyTranslationsAndValues(): void
    {
        $attr = new ProductAttribute(
            id: 'attr-002',
            productId: 'prod-001',
            attributeKey: 'size',
            allowedValues: [],
            translations: [],
        );

        self::assertSame([], $attr->allowedValues);
        self::assertSame([], $attr->translations);
    }
}
