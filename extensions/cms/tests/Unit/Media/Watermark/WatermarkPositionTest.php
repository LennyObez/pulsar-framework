<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Media\Watermark;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Media\Watermark\WatermarkPosition;

#[CoversNothing]
final class WatermarkPositionTest extends TestCase
{
    #[Test]
    public function has_nine_positions(): void
    {
        self::assertCount(9, WatermarkPosition::cases());
    }

    #[Test]
    #[DataProvider('positionValueProvider')]
    public function each_position_has_expected_string_value(WatermarkPosition $position, string $expectedValue): void
    {
        self::assertSame($expectedValue, $position->value);
    }

    /**
     * @return iterable<string, array{WatermarkPosition, string}>
     */
    public static function positionValueProvider(): iterable
    {
        yield 'TopLeft' => [WatermarkPosition::TopLeft, 'top-left'];
        yield 'TopCenter' => [WatermarkPosition::TopCenter, 'top-center'];
        yield 'TopRight' => [WatermarkPosition::TopRight, 'top-right'];
        yield 'MiddleLeft' => [WatermarkPosition::MiddleLeft, 'middle-left'];
        yield 'Center' => [WatermarkPosition::Center, 'center'];
        yield 'MiddleRight' => [WatermarkPosition::MiddleRight, 'middle-right'];
        yield 'BottomLeft' => [WatermarkPosition::BottomLeft, 'bottom-left'];
        yield 'BottomCenter' => [WatermarkPosition::BottomCenter, 'bottom-center'];
        yield 'BottomRight' => [WatermarkPosition::BottomRight, 'bottom-right'];
    }

    #[Test]
    #[DataProvider('positionValueProvider')]
    public function tryFrom_resolves_all_positions(WatermarkPosition $expected, string $value): void
    {
        self::assertSame($expected, WatermarkPosition::tryFrom($value));
    }

    #[Test]
    public function tryFrom_returns_null_for_invalid(): void
    {
        self::assertNull(WatermarkPosition::tryFrom('invalid'));
    }
}
