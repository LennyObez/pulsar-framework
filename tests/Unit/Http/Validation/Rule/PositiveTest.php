<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Positive;

#[CoversClass(Positive::class)]
final class PositiveTest extends TestCase
{
    private Positive $rule;

    protected function setUp(): void
    {
        $this->rule = new Positive();
    }

    #[Test]
    public function positiveIntegerPasses(): void
    {
        self::assertNull($this->rule->validate('field', 42, []));
    }

    #[Test]
    public function positiveFloatPasses(): void
    {
        self::assertNull($this->rule->validate('field', 3.14, []));
    }

    #[Test]
    public function positiveStringNumberPasses(): void
    {
        self::assertNull($this->rule->validate('field', '5', []));
    }

    #[Test]
    public function zeroFails(): void
    {
        $violation = $this->rule->validate('field', 0, []);
        self::assertNotNull($violation);
        self::assertSame('positive', $violation->rule);
    }

    #[Test]
    public function negativeNumberFails(): void
    {
        $violation = $this->rule->validate('field', -1, []);
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
        $rule = new Positive(message: 'Must be positive');
        $violation = $rule->validate('field', -1, []);
        self::assertNotNull($violation);
        self::assertSame('Must be positive', $violation->message);
    }

    #[Test]
    public function verySmallPositivePasses(): void
    {
        self::assertNull($this->rule->validate('field', 0.001, []));
    }
}
