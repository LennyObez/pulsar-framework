<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Legal;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Legal\BarNumber;

#[CoversClass(BarNumber::class)]
final class BarNumberTest extends TestCase
{
    private BarNumber $rule;

    protected function setUp(): void
    {
        $this->rule = new BarNumber();
    }

    #[Test]
    public function validDefaultPatternPasses(): void
    {
        self::assertNull($this->rule->validate('bar', 'CA12345678', []));
    }

    #[Test]
    public function fourDigitPasses(): void
    {
        self::assertNull($this->rule->validate('bar', 'NY1234', []));
    }

    #[Test]
    public function eightDigitPasses(): void
    {
        self::assertNull($this->rule->validate('bar', 'TX12345678', []));
    }

    #[Test]
    public function lowercasePrefixFails(): void
    {
        $violation = $this->rule->validate('bar', 'ca12345678', []);
        self::assertNotNull($violation);
        self::assertSame('bar_number', $violation->rule);
    }

    #[Test]
    public function noDigitsFails(): void
    {
        $violation = $this->rule->validate('bar', 'CALIFORNIA', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function singleLetterPrefixFails(): void
    {
        $violation = $this->rule->validate('bar', 'C12345', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customPatternPasses(): void
    {
        $rule = new BarNumber(pattern: '/^\d{6}$/');
        self::assertNull($rule->validate('bar', '123456', []));
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('bar', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('bar', null, []));
    }

    #[Test]
    public function nameReturnsBarNumber(): void
    {
        self::assertSame('bar_number', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new BarNumber(message: 'Custom bar number message');
        $violation = $rule->validate('bar', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom bar number message', $violation->message);
    }

    #[Test]
    public function threeDigitsFails(): void
    {
        $violation = $this->rule->validate('bar', 'CA123', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nineDigitsFails(): void
    {
        $violation = $this->rule->validate('bar', 'CA123456789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function invalidPatternThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('valid regular expression');

        new BarNumber(pattern: '/[invalid');
    }

    #[Test]
    public function excessivelyLongPatternThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('maximum length');

        new BarNumber(pattern: '/' . str_repeat('a', 500) . '/');
    }
}
