<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Healthcare;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Healthcare\Npi;

#[CoversClass(Npi::class)]
final class NpiTest extends TestCase
{
    private Npi $rule;

    protected function setUp(): void
    {
        $this->rule = new Npi();
    }

    #[Test]
    public function validNpiPasses(): void
    {
        // Known valid NPI: 1234567893 (passes Luhn with 80840 prefix)
        self::assertNull($this->rule->validate('npi', '1234567893', []));
    }

    #[Test]
    public function anotherValidNpiPasses(): void
    {
        // NPI: 1245319599
        self::assertNull($this->rule->validate('npi', '1245319599', []));
    }

    #[Test]
    public function invalidLuhnFails(): void
    {
        $violation = $this->rule->validate('npi', '1234567890', []);
        self::assertNotNull($violation);
        self::assertSame('npi', $violation->rule);
    }

    #[Test]
    public function tooFewDigitsFails(): void
    {
        $violation = $this->rule->validate('npi', '123456789', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooManyDigitsFails(): void
    {
        $violation = $this->rule->validate('npi', '12345678901', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonDigitsFails(): void
    {
        $violation = $this->rule->validate('npi', '12345678AB', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('npi', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('npi', null, []));
    }

    #[Test]
    public function nameReturnsNpi(): void
    {
        self::assertSame('npi', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Npi(message: 'Custom NPI message');
        $violation = $rule->validate('npi', '0000000000', []);
        self::assertNotNull($violation);
        self::assertSame('Custom NPI message', $violation->message);
    }
}
