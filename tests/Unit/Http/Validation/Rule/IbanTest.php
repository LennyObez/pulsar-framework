<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Iban;

#[CoversClass(Iban::class)]
final class IbanTest extends TestCase
{
    private Iban $rule;

    protected function setUp(): void
    {
        $this->rule = new Iban();
    }

    #[Test]
    public function validGermanIbanPasses(): void
    {
        self::assertNull($this->rule->validate('iban', 'DE89370400440532013000', []));
    }

    #[Test]
    public function validUkIbanPasses(): void
    {
        self::assertNull($this->rule->validate('iban', 'GB29NWBK60161331926819', []));
    }

    #[Test]
    public function validFrenchIbanPasses(): void
    {
        self::assertNull($this->rule->validate('iban', 'FR7630006000011234567890189', []));
    }

    #[Test]
    public function validIbanWithSpacesPasses(): void
    {
        self::assertNull($this->rule->validate('iban', 'DE89 3704 0044 0532 0130 00', []));
    }

    #[Test]
    public function validLowercasePasses(): void
    {
        self::assertNull($this->rule->validate('iban', 'de89370400440532013000', []));
    }

    #[Test]
    public function invalidChecksumFails(): void
    {
        $violation = $this->rule->validate('iban', 'DE00370400440532013000', []);
        self::assertNotNull($violation);
        self::assertSame('iban', $violation->rule);
    }

    #[Test]
    public function tooShortFails(): void
    {
        $violation = $this->rule->validate('iban', 'DE8937040044', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function tooLongFails(): void
    {
        $violation = $this->rule->validate('iban', 'DE89370400440532013000123456789012345', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonAlphaCountryCodeFails(): void
    {
        $violation = $this->rule->validate('iban', '12893704004405320130', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonDigitCheckDigitsFails(): void
    {
        $violation = $this->rule->validate('iban', 'DEAB370400440532013000', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('iban', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new Iban(message: 'Bad IBAN.');
        $violation = $rule->validate('iban', 'INVALID', []);
        self::assertNotNull($violation);
        self::assertSame('Bad IBAN.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('iban', $this->rule->name());
    }
}
