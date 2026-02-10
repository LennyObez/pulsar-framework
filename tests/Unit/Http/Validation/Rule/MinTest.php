<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Min;

#[CoversClass(Min::class)]
final class MinTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float, mixed}>
     */
    public static function atOrAboveMinProvider(): iterable
    {
        yield 'exactly at minimum' => [5, 5];
        yield 'above minimum' => [5, 10];
        yield 'numeric string at min' => [5, '5'];
        yield 'numeric string above min' => [5, '10'];
        yield 'float minimum exact' => [2.5, 2.5];
        yield 'float minimum above' => [2.5, 3.0];
        yield 'zero minimum' => [0, 0];
    }

    #[Test]
    #[DataProvider('atOrAboveMinProvider')]
    public function atOrAboveMinPasses(int|float $min, mixed $value): void
    {
        $rule = new Min($min);
        self::assertNull($rule->validate('field', $value, []));
    }

    /**
     * @return iterable<string, array{int|float, mixed}>
     */
    public static function belowMinProvider(): iterable
    {
        yield 'one below' => [5, 4];
        yield 'far below' => [5, -100];
        yield 'numeric string below' => [5, '3'];
        yield 'float just below' => [2.5, 2.0];
        yield 'non-numeric string' => [5, 'abc'];
        yield 'empty string' => [5, ''];
    }

    #[Test]
    #[DataProvider('belowMinProvider')]
    public function belowMinFails(int|float $min, mixed $value): void
    {
        $rule = new Min($min);
        $violation = $rule->validate('field', $value, []);
        self::assertNotNull($violation);
        self::assertSame('min', $violation->rule);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Min(5);
        self::assertNull($rule->validate('field', null, []));
    }
}
