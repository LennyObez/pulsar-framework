<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Between;

#[CoversClass(Between::class)]
final class BetweenTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, int|float, mixed}>
     */
    public static function inRangeProvider(): iterable
    {
        yield 'midpoint' => [1, 10, 5];
        yield 'lower boundary' => [1, 10, 1];
        yield 'upper boundary' => [1, 10, 10];
        yield 'numeric string' => [1, 10, '5'];
        yield 'float midpoint' => [1.5, 3.5, 2.0];
        yield 'float lower boundary' => [1.5, 3.5, 1.5];
        yield 'float upper boundary' => [1.5, 3.5, 3.5];
        yield 'zero in range' => [-5, 5, 0];
        yield 'negative range' => [-10, -1, -5];
    }

    #[Test]
    #[DataProvider('inRangeProvider')]
    public function inRangePasses(int|float $min, int|float $max, mixed $value): void
    {
        $rule = new Between($min, $max);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{int|float, int|float, mixed}>
     */
    public static function outOfRangeProvider(): iterable
    {
        yield 'just below lower' => [1, 10, 0];
        yield 'just above upper' => [1, 10, 11];
        yield 'far below' => [1, 10, -100];
        yield 'far above' => [1, 10, 100];
        yield 'float just below' => [1.5, 3.5, 1.4];
        yield 'non-numeric string' => [1, 10, 'abc'];
        yield 'empty string' => [1, 10, ''];
    }

    #[Test]
    #[DataProvider('outOfRangeProvider')]
    public function outOfRangeFails(int|float $min, int|float $max, mixed $value): void
    {
        $rule = new Between($min, $max);
        $violation = $rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('between', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Between(1, 10);
        self::assertNull($rule->validate('field', null, []));
    }
}
