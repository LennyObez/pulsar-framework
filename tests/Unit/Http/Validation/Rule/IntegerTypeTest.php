<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\IntegerType;

#[CoversClass(IntegerType::class)]
final class IntegerTypeTest extends TestCase
{
    private IntegerType $rule;

    protected function setUp(): void
    {
        $this->rule = new IntegerType();
    }

    #[Test]
    public function intPasses(): void
    {
        self::assertNull($this->rule->validate('field', 42, []));
    }

    #[Test]
    public function zeroIntPasses(): void
    {
        self::assertNull($this->rule->validate('field', 0, []));
    }

    #[Test]
    public function numericStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '42', []));
    }

    #[Test]
    public function negativeNumericStringPasses(): void
    {
        self::assertNull($this->rule->validate('field', '-5', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function nonNumericStringFails(): void
    {
        $violation = $this->rule->validate('field', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('integer', $violation->rule);
    }

    #[Test]
    public function floatStringFails(): void
    {
        $violation = $this->rule->validate('field', '3.14', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function boolFails(): void
    {
        $violation = $this->rule->validate('field', true, []);
        self::assertNotNull($violation);
    }
}
