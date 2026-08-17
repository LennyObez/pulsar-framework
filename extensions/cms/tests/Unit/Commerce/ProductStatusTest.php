<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Commerce;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Commerce\ProductStatus;

#[CoversNothing]
final class ProductStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{ProductStatus, ProductStatus, bool}>
     */
    public static function transitionProvider(): iterable
    {
        yield 'draft to active' => [ProductStatus::Draft, ProductStatus::Active, true];
        yield 'draft to archived' => [ProductStatus::Draft, ProductStatus::Archived, false];
        yield 'active to archived' => [ProductStatus::Active, ProductStatus::Archived, true];
        yield 'active to draft' => [ProductStatus::Active, ProductStatus::Draft, false];
        yield 'archived to draft' => [ProductStatus::Archived, ProductStatus::Draft, true];
        yield 'archived to active' => [ProductStatus::Archived, ProductStatus::Active, false];
    }

    #[Test]
    #[DataProvider('transitionProvider')]
    public function canTransitionTo_validates_transitions(
        ProductStatus $from,
        ProductStatus $to,
        bool $expected,
    ): void {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    #[Test]
    public function canTransitionTo_rejects_self_transitions(): void
    {
        foreach (ProductStatus::cases() as $status) {
            self::assertFalse($status->canTransitionTo($status));
        }
    }

    #[Test]
    public function isPubliclyVisible_returns_true_only_for_active(): void
    {
        self::assertTrue(ProductStatus::Active->isPubliclyVisible());
        self::assertFalse(ProductStatus::Draft->isPubliclyVisible());
        self::assertFalse(ProductStatus::Archived->isPubliclyVisible());
    }

    #[Test]
    public function label_returns_human_readable_names(): void
    {
        self::assertSame('Draft', ProductStatus::Draft->label());
        self::assertSame('Active', ProductStatus::Active->label());
        self::assertSame('Archived', ProductStatus::Archived->label());
    }
}
