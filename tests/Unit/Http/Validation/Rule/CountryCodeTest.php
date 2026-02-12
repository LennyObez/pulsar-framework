<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\CountryCode;

#[CoversClass(CountryCode::class)]
final class CountryCodeTest extends TestCase
{
    private CountryCode $rule;

    protected function setUp(): void
    {
        $this->rule = new CountryCode();
    }

    #[Test]
    public function validCodePasses(): void
    {
        self::assertNull($this->rule->validate('country', 'US', []));
    }

    #[Test]
    public function lowercasePasses(): void
    {
        self::assertNull($this->rule->validate('country', 'de', []));
    }

    #[Test]
    public function invalidCodeFails(): void
    {
        $violation = $this->rule->validate('country', 'XX', []);
        self::assertNotNull($violation);
        self::assertSame('country_code', $violation->rule);
    }

    #[Test]
    public function threeLetterCodeFails(): void
    {
        $violation = $this->rule->validate('country', 'USA', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function numericCodeFails(): void
    {
        $violation = $this->rule->validate('country', '123', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('country', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('country', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new CountryCode(message: 'Bad country.');
        $violation = $rule->validate('country', 'ZZ', []);
        self::assertNotNull($violation);
        self::assertSame('Bad country.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('country_code', $this->rule->name());
    }
}
