<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\LessThan;

#[CoversClass(LessThan::class)]
final class LessThanTest extends TestCase
{
    #[Test]
    public function valueBelowThresholdPasses(): void
    {
        $rule = new LessThan(10);
        self::assertNull($rule->validate('field', 5, []));
    }

    #[Test]
    public function valueEqualToThresholdFails(): void
    {
        $rule = new LessThan(10);
        $violation = $rule->validate('field', 10, []);
        self::assertNotNull($violation);
        self::assertSame('less_than', $violation->rule);
    }

    #[Test]
    public function valueAboveThresholdFails(): void
    {
        $rule = new LessThan(10);
        $violation = $rule->validate('field', 15, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function floatBelowThresholdPasses(): void
    {
        $rule = new LessThan(3.14);
        self::assertNull($rule->validate('field', 3.13, []));
    }

    #[Test]
    public function stringNumberPasses(): void
    {
        $rule = new LessThan(10);
        self::assertNull($rule->validate('field', '5', []));
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new LessThan(10);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new LessThan(10);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new LessThan(100, message: 'Must be under 100');
        $violation = $rule->validate('field', 200, []);
        self::assertNotNull($violation);
        self::assertSame('Must be under 100', $violation->message);
    }

    #[Test]
    public function negativeValueBelowThresholdPasses(): void
    {
        $rule = new LessThan(0);
        self::assertNull($rule->validate('field', -5, []));
    }
}
