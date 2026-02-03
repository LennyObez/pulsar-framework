<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Between;

#[CoversClass(Between::class)]
final class BetweenTest extends TestCase
{
    #[Test]
    public function withinRangePasses(): void
    {
        $rule = new Between(1, 10);
        self::assertNull($rule->validate('field', 5, []));
    }

    #[Test]
    public function atLowerBoundaryPasses(): void
    {
        $rule = new Between(1, 10);
        self::assertNull($rule->validate('field', 1, []));
    }

    #[Test]
    public function atUpperBoundaryPasses(): void
    {
        $rule = new Between(1, 10);
        self::assertNull($rule->validate('field', 10, []));
    }

    #[Test]
    public function belowRangeFails(): void
    {
        $rule = new Between(1, 10);
        $violation = $rule->validate('field', 0, []);
        self::assertNotNull($violation);
        self::assertSame('between', $violation->rule);
    }

    #[Test]
    public function aboveRangeFails(): void
    {
        $rule = new Between(1, 10);
        $violation = $rule->validate('field', 11, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new Between(1, 10);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Between(1, 10);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function numericStringInRangePasses(): void
    {
        $rule = new Between(1, 10);
        self::assertNull($rule->validate('field', '5', []));
    }

    #[Test]
    public function floatBoundaries(): void
    {
        $rule = new Between(1.5, 3.5);
        self::assertNull($rule->validate('field', 2.0, []));
        self::assertNull($rule->validate('field', 1.5, []));
        self::assertNull($rule->validate('field', 3.5, []));

        $violation = $rule->validate('field', 1.4, []);
        self::assertNotNull($violation);
    }
}
