<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Negative;

#[CoversClass(Negative::class)]
final class NegativeTest extends TestCase
{
    private Negative $rule;

    protected function setUp(): void
    {
        $this->rule = new Negative();
    }

    #[Test]
    public function negativeIntegerPasses(): void
    {
        self::assertNull($this->rule->validate('field', -1, []));
    }

    #[Test]
    public function negativeFloatPasses(): void
    {
        self::assertNull($this->rule->validate('field', -3.14, []));
    }

    #[Test]
    public function negativeStringNumberPasses(): void
    {
        self::assertNull($this->rule->validate('field', '-5', []));
    }

    #[Test]
    public function zeroFails(): void
    {
        $violation = $this->rule->validate('field', 0, []);
        self::assertNotNull($violation);
        self::assertSame('negative', $violation->rule);
    }

    #[Test]
    public function positiveNumberFails(): void
    {
        $violation = $this->rule->validate('field', 1, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $violation = $this->rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Negative(message: 'Must be negative');
        $violation = $rule->validate('field', 1, []);
        self::assertNotNull($violation);
        self::assertSame('Must be negative', $violation->message);
    }

    #[Test]
    public function verySmallNegativePasses(): void
    {
        self::assertNull($this->rule->validate('field', -0.001, []));
    }
}
