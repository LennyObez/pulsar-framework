<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Decimal;

#[CoversClass(Decimal::class)]
final class DecimalTest extends TestCase
{
    #[Test]
    public function exactDecimalPlacesPasses(): void
    {
        $rule = new Decimal(2);
        self::assertNull($rule->validate('field', '3.14', []));
    }

    #[Test]
    public function tooFewDecimalPlacesFails(): void
    {
        $rule = new Decimal(2);
        $violation = $rule->validate('field', '3.1', []);
        self::assertNotNull($violation);
        self::assertSame('decimal', $violation->rule);
    }

    #[Test]
    public function tooManyDecimalPlacesFails(): void
    {
        $rule = new Decimal(2);
        $violation = $rule->validate('field', '3.141', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function rangeOfDecimalPlacesPasses(): void
    {
        $rule = new Decimal(1, 3);
        self::assertNull($rule->validate('field', '3.14', []));
    }

    #[Test]
    public function noDecimalPlacesWithMinZeroPasses(): void
    {
        $rule = new Decimal(0, 2);
        self::assertNull($rule->validate('field', '42', []));
    }

    #[Test]
    public function integerWithExactZeroDecimalsPasses(): void
    {
        $rule = new Decimal(0);
        self::assertNull($rule->validate('field', '42', []));
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $rule = new Decimal(2);
        $violation = $rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Decimal(2);
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Decimal(2, message: 'Must have 2 decimals');
        $violation = $rule->validate('field', '3.1', []);
        self::assertNotNull($violation);
        self::assertSame('Must have 2 decimals', $violation->message);
    }

    #[Test]
    public function numericIntegerPasses(): void
    {
        $rule = new Decimal(0, 2);
        self::assertNull($rule->validate('field', 42, []));
    }
}
