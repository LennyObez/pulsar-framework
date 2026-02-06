<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\AlphaNumeric;

#[CoversClass(AlphaNumeric::class)]
final class AlphaNumericTest extends TestCase
{
    private AlphaNumeric $rule;

    protected function setUp(): void
    {
        $this->rule = new AlphaNumeric();
    }

    #[Test]
    public function lettersAndDigitsPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'abc123', []));
    }

    #[Test]
    public function lettersOnlyPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'hello', []));
    }

    #[Test]
    public function digitsOnlyPasses(): void
    {
        self::assertNull($this->rule->validate('field', '12345', []));
    }

    #[Test]
    public function stringWithSpecialCharsFails(): void
    {
        $violation = $this->rule->validate('field', 'abc-123', []);
        self::assertNotNull($violation);
        self::assertSame('alpha_numeric', $violation->rule);
    }

    #[Test]
    public function stringWithSpacesFails(): void
    {
        $violation = $this->rule->validate('field', 'hello world', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function unicodeModeAcceptsAccented(): void
    {
        $rule = new AlphaNumeric(unicode: true);
        self::assertNull($rule->validate('field', "caf\u{00E9}123", []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new AlphaNumeric(message: 'Alphanumeric only');
        $violation = $rule->validate('field', 'a b', []);
        self::assertNotNull($violation);
        self::assertSame('Alphanumeric only', $violation->message);
    }
}
