<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Live\CssColor;

#[CoversClass(CssColor::class)]
final class CssColorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validColors(): iterable
    {
        yield 'short hex' => ['#abc'];
        yield 'short hex with alpha' => ['#abcd'];
        yield 'full hex' => ['#4f46e5'];
        yield 'full hex with alpha' => ['#4f46e5ff'];
        yield 'rgb' => ['rgb(79, 70, 229)'];
        yield 'rgba' => ['rgba(79, 70, 229, 0.5)'];
        yield 'hsl' => ['hsl(244, 75%, 59%)'];
        yield 'hsla' => ['hsla(244, 75%, 59%, 0.5)'];
        yield 'named color' => ['rebeccapurple'];
        yield 'transparent keyword' => ['transparent'];
    }

    #[Test]
    #[DataProvider('validColors')]
    public function validColorsPassThroughUnchanged(string $color): void
    {
        self::assertSame($color, CssColor::sanitize($color));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousColors(): iterable
    {
        yield 'custom-property breakout' => ['#000; background: url(//evil.com)'];
        yield 'rule-context breakout' => ['}body{background:url(//evil.com'];
        yield 'expression' => ['expression(alert(1))'];
        yield 'url function' => ['url(//evil.com)'];
        yield 'semicolon' => ['red;'];
        yield 'whitespace injection' => ['red blue'];
        yield 'empty string' => [''];
        yield 'html angle brackets' => ['<script>'];
    }

    #[Test]
    #[DataProvider('maliciousColors')]
    public function maliciousColorsFallBackToDefault(string $color): void
    {
        self::assertSame('#4f46e5', CssColor::sanitize($color));
    }

    #[Test]
    public function customFallbackIsHonored(): void
    {
        self::assertSame('#000000', CssColor::sanitize('red; evil', '#000000'));
    }

    #[Test]
    public function surroundingWhitespaceIsTrimmedForValidColors(): void
    {
        self::assertSame('#abc123', CssColor::sanitize('  #abc123  '));
    }
}
