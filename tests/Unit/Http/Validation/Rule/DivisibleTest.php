<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Divisible;

#[CoversClass(Divisible::class)]
final class DivisibleTest extends TestCase
{
    #[Test]
    public function divisibleByTwoPasses(): void
    {
        $rule = new Divisible(2);
        self::assertNull($rule->validate('field', 10, []));
    }

    #[Test]
    public function notDivisibleFails(): void
    {
        $rule = new Divisible(3);
        $violation = $rule->validate('field', 10, []);
        self::assertNotNull($violation);
        self::assertSame('divisible', $violation->rule);
    }

    #[Test]
    public function divisibleByFloatPasses(): void
    {
        $rule = new Divisible(0.5);
        self::assertNull($rule->validate('field', 2.5, []));
    }

    #[Test]
    public function zeroDivisibleByAnyPasses(): void
    {
        $rule = new Divisible(7);
        self::assertNull($rule->validate('field', 0, []));
    }

    #[Test]
    public function stringNumberPasses(): void
    {
        $rule = new Divisible(5);
        self::assertNull($rule->validate('field', '15', []));
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new Divisible(2);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Divisible(2);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Divisible(2, message: 'Must be even');
        $violation = $rule->validate('field', 3, []);
        self::assertNotNull($violation);
        self::assertSame('Must be even', $violation->message);
    }

    #[Test]
    public function negativeDivisiblePasses(): void
    {
        $rule = new Divisible(3);
        self::assertNull($rule->validate('field', -9, []));
    }
}
