<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Max;

#[CoversClass(Max::class)]
final class MaxTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, mixed}>
     */
    public static function atOrBelowMaxProvider(): iterable
    {
        yield 'exactly at maximum' => [10, 10];
        yield 'below maximum' => [10, 5];
        yield 'numeric string at max' => [10, '10'];
        yield 'numeric string below max' => [10, '5'];
        yield 'float maximum exact' => [5.5, 5.5];
        yield 'float maximum below' => [5.5, 3.0];
        yield 'zero maximum' => [0, 0];
    }

    #[Test]
    #[DataProvider('atOrBelowMaxProvider')]
    public function atOrBelowMaxPasses(int|float $max, mixed $value): void
    {
        $rule = new Max($max);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{int|float, mixed}>
     */
    public static function aboveMaxProvider(): iterable
    {
        yield 'one above' => [10, 11];
        yield 'far above' => [10, 1000];
        yield 'numeric string above' => [10, '15'];
        yield 'float just above' => [5.5, 6.0];
        yield 'non-numeric string' => [10, 'abc'];
        yield 'empty string' => [10, ''];
    }

    #[Test]
    #[DataProvider('aboveMaxProvider')]
    public function aboveMaxFails(int|float $max, mixed $value): void
    {
        $rule = new Max($max);
        $violation = $rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('max', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Max(10);
        self::assertNull($rule->validate('field', null, []));
    }
}
