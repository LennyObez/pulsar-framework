<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\ABTest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;

#[CoversClass(ExperimentVariant::class)]
final class ExperimentVariantTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $variant = new ExperimentVariant(
            id: 'var-001',
            experimentId: 'exp-001',
            name: 'Control',
            contentId: 'content-a',
            weight: 50,
        );

        self::assertSame('var-001', $variant->id);
        self::assertSame('exp-001', $variant->experimentId);
        self::assertSame('Control', $variant->name);
        self::assertSame('content-a', $variant->contentId);
        self::assertSame(50, $variant->weight);
    }

    #[Test]
    public function variantCanHaveZeroWeight(): void
    {
        $variant = new ExperimentVariant(
            id: 'var-002',
            experimentId: 'exp-001',
            name: 'Disabled',
            contentId: 'content-b',
            weight: 0,
        );

        self::assertSame(0, $variant->weight);
    }
}
