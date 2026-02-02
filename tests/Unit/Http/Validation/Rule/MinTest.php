<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Min;

#[CoversClass(Min::class)]
final class MinTest extends TestCase
{
    #[Test]
    public function atMinimumPasses(): void
    {
        $rule = new Min(5);
        self::assertNull($rule->validate('field', 5, []));
    }

    #[Test]
    public function aboveMinimumPasses(): void
    {
        $rule = new Min(5);
        self::assertNull($rule->validate('field', 10, []));
    }

    #[Test]
    public function belowMinimumFails(): void
    {
        $rule = new Min(5);
        $violation = $rule->validate('field', 3, []);
        self::assertNotNull($violation);
        self::assertSame('min', $violation->rule);
    }

    #[Test]
    public function numericStringPasses(): void
    {
        $rule = new Min(5);
        self::assertNull($rule->validate('field', '10', []));
    }

    #[Test]
    public function numericStringBelowFails(): void
    {
        $rule = new Min(5);
        $violation = $rule->validate('field', '3', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new Min(5);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Min(5);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function floatMinimum(): void
    {
        $rule = new Min(2.5);
        self::assertNull($rule->validate('field', 2.5, []));
        self::assertNull($rule->validate('field', 3.0, []));

        $violation = $rule->validate('field', 2.0, []);
        self::assertNotNull($violation);
    }
}
