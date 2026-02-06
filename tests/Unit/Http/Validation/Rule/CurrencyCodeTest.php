<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\CurrencyCode;

#[CoversClass(CurrencyCode::class)]
final class CurrencyCodeTest extends TestCase
{
    private CurrencyCode $rule;

    protected function setUp(): void
    {
        $this->rule = new CurrencyCode();
    }

    #[Test]
    public function validCodePasses(): void
    {
        self::assertNull($this->rule->validate('currency', 'USD', []));
    }

    #[Test]
    public function validEurPasses(): void
    {
        self::assertNull($this->rule->validate('currency', 'EUR', []));
    }

    #[Test]
    public function lowercasePasses(): void
    {
        self::assertNull($this->rule->validate('currency', 'gbp', []));
    }

    #[Test]
    public function invalidCodeFails(): void
    {
        $violation = $this->rule->validate('currency', 'XYZ', []);
        self::assertNotNull($violation);
        self::assertSame('currency_code', $violation->rule);
    }

    #[Test]
    public function twoLetterCodeFails(): void
    {
        $violation = $this->rule->validate('currency', 'US', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('currency', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('currency', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new CurrencyCode(message: 'Bad currency.');
        $violation = $rule->validate('currency', 'FAKE', []);
        self::assertNotNull($violation);
        self::assertSame('Bad currency.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('currency_code', $this->rule->name());
    }
}
