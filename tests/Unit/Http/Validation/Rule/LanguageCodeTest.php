<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\LanguageCode;

#[CoversClass(LanguageCode::class)]
final class LanguageCodeTest extends TestCase
{
    private LanguageCode $rule;

    protected function setUp(): void
    {
        $this->rule = new LanguageCode();
    }

    #[Test]
    public function validCodePasses(): void
    {
        self::assertNull($this->rule->validate('lang', 'en', []));
    }

    #[Test]
    public function validFrenchPasses(): void
    {
        self::assertNull($this->rule->validate('lang', 'fr', []));
    }

    #[Test]
    public function uppercasePasses(): void
    {
        self::assertNull($this->rule->validate('lang', 'DE', []));
    }

    #[Test]
    public function invalidCodeFails(): void
    {
        $violation = $this->rule->validate('lang', 'xx', []);
        self::assertNotNull($violation);
        self::assertSame('language_code', $violation->rule);
    }

    #[Test]
    public function threeLetterCodeFails(): void
    {
        $violation = $this->rule->validate('lang', 'eng', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('lang', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('lang', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new LanguageCode(message: 'Bad lang.');
        $violation = $rule->validate('lang', 'zz', []);
        self::assertNotNull($violation);
        self::assertSame('Bad lang.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('language_code', $this->rule->name());
    }
}
