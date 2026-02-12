<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Identity\Ssn;

#[CoversClass(Ssn::class)]
final class SsnTest extends TestCase
{
    private Ssn $rule;

    protected function setUp(): void
    {
        $this->rule = new Ssn();
    }

    #[Test]
    public function validDashedFormatPasses(): void
    {
        self::assertNull($this->rule->validate('ssn', '123-45-6789', []));
    }

    #[Test]
    public function validContinuousFormatPasses(): void
    {
        self::assertNull($this->rule->validate('ssn', '123456789', []));
    }

    #[Test]
    public function areaZeroFails(): void
    {
        $violation = $this->rule->validate('ssn', '000-45-6789', []);
        self::assertNotNull($violation);
        self::assertSame('ssn', $violation->rule);
    }

    #[Test]
    public function area666Fails(): void
    {
        $violation = $this->rule->validate('ssn', '666-45-6789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function area900Fails(): void
    {
        $violation = $this->rule->validate('ssn', '900-45-6789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function groupZeroFails(): void
    {
        $violation = $this->rule->validate('ssn', '123-00-6789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function serialZeroFails(): void
    {
        $violation = $this->rule->validate('ssn', '123-45-0000', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function area899Passes(): void
    {
        self::assertNull($this->rule->validate('ssn', '899-01-0001', []));
    }

    #[Test]
    public function area001Passes(): void
    {
        self::assertNull($this->rule->validate('ssn', '001-01-0001', []));
    }

    #[Test]
    public function nonDigitsFails(): void
    {
        $violation = $this->rule->validate('ssn', 'ABC-DE-FGHI', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooFewDigitsFails(): void
    {
        $violation = $this->rule->validate('ssn', '12345678', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooManyDigitsFails(): void
    {
        $violation = $this->rule->validate('ssn', '1234567890', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('ssn', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('ssn', null, []));
    }

    #[Test]
    public function nameReturnsSsn(): void
    {
        self::assertSame('ssn', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Ssn(message: 'Custom SSN message');
        $violation = $rule->validate('ssn', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom SSN message', $violation->message);
    }

    #[Test]
    public function area665Passes(): void
    {
        self::assertNull($this->rule->validate('ssn', '665-01-0001', []));
    }

    #[Test]
    public function area667Passes(): void
    {
        self::assertNull($this->rule->validate('ssn', '667-01-0001', []));
    }

    #[Test]
    public function area999Fails(): void
    {
        $violation = $this->rule->validate('ssn', '999-01-0001', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function group99Passes(): void
    {
        self::assertNull($this->rule->validate('ssn', '123-99-0001', []));
    }

    #[Test]
    public function serial9999Passes(): void
    {
        self::assertNull($this->rule->validate('ssn', '123-01-9999', []));
    }

    #[Test]
    public function integerInputFails(): void
    {
        $violation = $this->rule->validate('ssn', 123456789, []);
        self::assertNotNull($violation);
    }
}
