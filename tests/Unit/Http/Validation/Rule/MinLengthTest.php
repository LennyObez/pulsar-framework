<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\MinLength;

#[CoversClass(MinLength::class)]
final class MinLengthTest extends TestCase
{
    /**
     * @return iterable<string, array{int, mixed}>
     */
    public static function atOrAboveMinLengthProvider(): iterable
    {
        yield 'exactly at minimum' => [3, 'abc'];
        yield 'above minimum' => [3, 'abcdef'];
        yield 'unicode characters counted correctly' => [3, "\u{00E9}\u{00E9}\u{00E9}"];
        yield 'long string' => [1, str_repeat('x', 1000)];
        yield 'minimum 1 single char' => [1, 'a'];
        yield 'spaces count as length' => [3, '   '];
    }

    #[Test]
    #[DataProvider('atOrAboveMinLengthProvider')]
    public function atOrAboveMinLengthPasses(int $minLength, mixed $value): void
    {
        $rule = new MinLength($minLength);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{int, mixed}>
     */
    public static function belowMinLengthProvider(): iterable
    {
        yield 'one below minimum' => [3, 'ab'];
        yield 'empty string' => [3, ''];
        yield 'single char below min of 2' => [2, 'a'];
        yield 'unicode string too short' => [4, "\u{00E9}\u{00E9}\u{00E9}"];
        yield 'empty string against min 1' => [1, ''];
    }

    #[Test]
    #[DataProvider('belowMinLengthProvider')]
    public function belowMinLengthFails(int $minLength, mixed $value): void
    {
        $rule = new MinLength($minLength);
        $violation = $rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('min_length', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new MinLength(3);
        self::assertNull($rule->validate('field', null, []));
    }
}
