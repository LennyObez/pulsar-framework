<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\GreaterThan;

#[CoversClass(GreaterThan::class)]
final class GreaterThanTest extends TestCase
{
    #[Test]
    public function valueAboveThresholdPasses(): void
    {
        $rule = new GreaterThan(10);
        self::assertNull($rule->validate('field', 11, []));
    }

    #[Test]
    public function valueEqualToThresholdFails(): void
    {
        $rule = new GreaterThan(10);
        $violation = $rule->validate('field', 10, []);
        self::assertNotNull($violation);
        self::assertSame('greater_than', $violation->rule);
    }

    #[Test]
    public function valueBelowThresholdFails(): void
    {
        $rule = new GreaterThan(10);
        $violation = $rule->validate('field', 5, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function floatAboveThresholdPasses(): void
    {
        $rule = new GreaterThan(3.14);
        self::assertNull($rule->validate('field', 3.15, []));
    }

    #[Test]
    public function stringNumberPasses(): void
    {
        $rule = new GreaterThan(0);
        self::assertNull($rule->validate('field', '5', []));
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new GreaterThan(0);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new GreaterThan(0);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new GreaterThan(0, message: 'Must be positive');
        $violation = $rule->validate('field', -1, []);
        self::assertNotNull($violation);
        self::assertSame('Must be positive', $violation->message);
    }

    #[Test]
    public function negativeThresholdPasses(): void
    {
        $rule = new GreaterThan(-10);
        self::assertNull($rule->validate('field', -5, []));
    }
}
