<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\BadgeVariant;

#[CoversClass(BadgeVariant::class)]
final class BadgeVariantTest extends TestCase
{
    #[Test]
    #[DataProvider('variantProvider')]
    public function caseHasCorrectStringValue(BadgeVariant $variant, string $expected): void
    {
        self::assertSame($expected, $variant->value);
    }

    /**
     * @return iterable<string, array{BadgeVariant, string}>
     */
    public static function variantProvider(): iterable
    {
        yield 'Success' => [BadgeVariant::Success, 'success'];
        yield 'Warning' => [BadgeVariant::Warning, 'warning'];
        yield 'Error' => [BadgeVariant::Error, 'error'];
        yield 'Info' => [BadgeVariant::Info, 'info'];
        yield 'Neutral' => [BadgeVariant::Neutral, 'neutral'];
    }

    #[Test]
    public function allCasesAreMapped(): void
    {
        self::assertCount(5, BadgeVariant::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(BadgeVariant::tryFrom('danger'));
    }
}
