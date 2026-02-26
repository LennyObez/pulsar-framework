<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\MaxLength;

#[CoversClass(MaxLength::class)]
final class MaxLengthTest extends TestCase
{
    /**
     * @return iterable<string, array{int, mixed}>
     */
    public static function atOrBelowMaxLengthProvider(): iterable
    {
        yield 'exactly at maximum' => [5, 'abcde'];
        yield 'below maximum' => [5, 'abc'];
        yield 'empty string' => [5, ''];
        yield 'empty string max 0' => [0, ''];
        yield 'unicode at max' => [3, "\u{00E9}\u{00E9}\u{00E9}"];
        yield 'single char at max 1' => [1, 'x'];
    }

    #[Test]
    #[DataProvider('atOrBelowMaxLengthProvider')]
    public function atOrBelowMaxLengthPasses(int $maxLength, mixed $value): void
    {
        $rule = new MaxLength($maxLength);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{int, mixed}>
     */
    public static function aboveMaxLengthProvider(): iterable
    {
        yield 'one above maximum' => [5, 'abcdef'];
        yield 'far above maximum' => [5, str_repeat('x', 100)];
        yield 'unicode too long' => [2, "\u{00E9}\u{00E9}\u{00E9}"];
        yield 'any string when max is 0' => [0, 'a'];
    }

    #[Test]
    #[DataProvider('aboveMaxLengthProvider')]
    public function aboveMaxLengthFails(int $maxLength, mixed $value): void
    {
        $rule = new MaxLength($maxLength);
        $violation = $rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('max_length', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new MaxLength(5);
        self::assertNull($rule->validate('field', null, []));
    }
}
