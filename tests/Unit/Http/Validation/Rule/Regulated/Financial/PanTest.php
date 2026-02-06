<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Financial;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Financial\Pan;

#[CoversClass(Pan::class)]
final class PanTest extends TestCase
{
    private Pan $rule;

    protected function setUp(): void
    {
        $this->rule = new Pan();
    }

    #[Test]
    public function validVisaPasses(): void
    {
        // Known Luhn-valid test number
        self::assertNull($this->rule->validate('card', '4111111111111111', []));
    }

    #[Test]
    public function validWithSpacesPasses(): void
    {
        self::assertNull($this->rule->validate('card', '4111 1111 1111 1111', []));
    }

    #[Test]
    public function validWithDashesPasses(): void
    {
        self::assertNull($this->rule->validate('card', '4111-1111-1111-1111', []));
    }

    #[Test]
    public function validMastercardPasses(): void
    {
        self::assertNull($this->rule->validate('card', '5500000000000004', []));
    }

    #[Test]
    public function invalidLuhnFails(): void
    {
        $violation = $this->rule->validate('card', '4111111111111112', []);
        self::assertNotNull($violation);
        self::assertSame('pan', $violation->rule);
    }

    #[Test]
    public function tooShortFails(): void
    {
        $violation = $this->rule->validate('card', '411111111111', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooLongFails(): void
    {
        $violation = $this->rule->validate('card', '41111111111111111111', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonDigitsFails(): void
    {
        $violation = $this->rule->validate('card', 'abcdefghijklm', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('card', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('card', null, []));
    }

    #[Test]
    public function nameReturnsPan(): void
    {
        self::assertSame('pan', $this->rule->name());
    }

    #[Test]
    public function thirteenDigitValidPasses(): void
    {
        // 13-digit Luhn-valid: 4222222222222
        self::assertNull($this->rule->validate('card', '4222222222222', []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Pan(message: 'Custom PAN message');
        $violation = $rule->validate('card', 'invalid', []);
        self::assertNotNull($violation);
        self::assertSame('Custom PAN message', $violation->message);
    }

    #[Test]
    public function nineteenDigitValidPasses(): void
    {
        // 19-digit number: all zeros is trivially Luhn-valid (sum=0)
        self::assertNull($this->rule->validate('card', '0000000000000000000', []));
    }

    #[Test]
    public function fifteenDigitAmexPasses(): void
    {
        // AMEX test number (15 digits, Luhn-valid)
        self::assertNull($this->rule->validate('card', '378282246310005', []));
    }

    #[Test]
    public function integerInputFails(): void
    {
        // Integer input must be rejected (only strings accepted)
        $violation = $this->rule->validate('card', 4111111111111111, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function fifteenDigitInvalidLuhnFails(): void
    {
        $violation = $this->rule->validate('card', '378282246310006', []);
        self::assertNotNull($violation);
    }
}
