<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ProductTranslation;

#[CoversClass(ProductTranslation::class)]
final class ProductTranslationTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $translation = new ProductTranslation(
            id: 'pt-001',
            productId: 'prod-001',
            locale: 'de',
            name: 'Premium Paket',
            description: 'Unser bestes Angebot',
            slug: 'premium-paket',
        );

        self::assertSame('pt-001', $translation->id);
        self::assertSame('prod-001', $translation->productId);
        self::assertSame('de', $translation->locale);
        self::assertSame('Premium Paket', $translation->name);
        self::assertSame('Unser bestes Angebot', $translation->description);
        self::assertSame('premium-paket', $translation->slug);
    }
}
