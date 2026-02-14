<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Financial;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Financial\Cvv;

#[CoversClass(Cvv::class)]
final class CvvTest extends TestCase
{
    private Cvv $rule;

    protected function setUp(): void
    {
        $this->rule = new Cvv();
    }

    #[Test]
    public function threeDigitPasses(): void
    {
        self::assertNull($this->rule->validate('cvv', '123', []));
    }

    #[Test]
    public function fourDigitPasses(): void
    {
        self::assertNull($this->rule->validate('cvv', '1234', []));
    }

    #[Test]
    public function twoDigitsFails(): void
    {
        $violation = $this->rule->validate('cvv', '12', []);
        self::assertNotNull($violation);
        self::assertSame('cvv', $violation->rule);
    }

    #[Test]
    public function fiveDigitsFails(): void
    {
        $violation = $this->rule->validate('cvv', '12345', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonDigitsFails(): void
    {
        $violation = $this->rule->validate('cvv', 'abc', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('cvv', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('cvv', null, []));
    }

    #[Test]
    public function nameReturnsCvv(): void
    {
        self::assertSame('cvv', $this->rule->name());
    }

    #[Test]
    public function allZeroesPasses(): void
    {
        self::assertNull($this->rule->validate('cvv', '000', []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Cvv(message: 'Custom CVV message');
        $violation = $rule->validate('cvv', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom CVV message', $violation->message);
    }

    #[Test]
    public function mixedAlphaNumericFails(): void
    {
        $violation = $this->rule->validate('cvv', '12a', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function fourDigitAmexPasses(): void
    {
        self::assertNull($this->rule->validate('cvv', '9999', []));
    }
}
