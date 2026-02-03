<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Max;

#[CoversClass(Max::class)]
final class MaxTest extends TestCase
{
    #[Test]
    public function atMaximumPasses(): void
    {
        $rule = new Max(10);
        self::assertNull($rule->validate('field', 10, []));
    }

    #[Test]
    public function belowMaximumPasses(): void
    {
        $rule = new Max(10);
        self::assertNull($rule->validate('field', 5, []));
    }

    #[Test]
    public function aboveMaximumFails(): void
    {
        $rule = new Max(10);
        $violation = $rule->validate('field', 15, []);
        self::assertNotNull($violation);
        self::assertSame('max', $violation->rule);
    }

    #[Test]
    public function numericStringPasses(): void
    {
        $rule = new Max(10);
        self::assertNull($rule->validate('field', '5', []));
    }

    #[Test]
    public function numericStringAboveFails(): void
    {
        $rule = new Max(10);
        $violation = $rule->validate('field', '15', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new Max(10);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Max(10);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function floatMaximum(): void
    {
        $rule = new Max(5.5);
        self::assertNull($rule->validate('field', 5.5, []));
        self::assertNull($rule->validate('field', 3.0, []));

        $violation = $rule->validate('field', 6.0, []);
        self::assertNotNull($violation);
    }
}
