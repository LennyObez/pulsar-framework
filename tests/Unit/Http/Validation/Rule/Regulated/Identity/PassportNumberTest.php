<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Identity\PassportNumber;

#[CoversClass(PassportNumber::class)]
final class PassportNumberTest extends TestCase
{
    #[Test]
    public function defaultPatternSingleLetterPrefixPasses(): void
    {
        $rule = new PassportNumber();
        self::assertNull($rule->validate('passport', 'A12345678', []));
    }

    #[Test]
    public function defaultPatternTwoLetterPrefixPasses(): void
    {
        $rule = new PassportNumber();
        self::assertNull($rule->validate('passport', 'AB123456', []));
    }

    #[Test]
    public function usPatternPasses(): void
    {
        $rule = new PassportNumber(country: 'US');
        self::assertNull($rule->validate('passport', 'C12345678', []));
    }

    #[Test]
    public function gbPatternPasses(): void
    {
        $rule = new PassportNumber(country: 'GB');
        self::assertNull($rule->validate('passport', '123456789', []));
    }

    #[Test]
    public function caPatternPasses(): void
    {
        $rule = new PassportNumber(country: 'CA');
        self::assertNull($rule->validate('passport', 'AB123456', []));
    }

    #[Test]
    public function invalidFormatFails(): void
    {
        $rule = new PassportNumber();
        $violation = $rule->validate('passport', '12345', []);
        self::assertNotNull($violation);
        self::assertSame('passport_number', $violation->rule);
    }

    #[Test]
    public function lowercaseFails(): void
    {
        $rule = new PassportNumber();
        $violation = $rule->validate('passport', 'a12345678', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function noDigitsFails(): void
    {
        $rule = new PassportNumber();
        $violation = $rule->validate('passport', 'ABCDEFGHI', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $rule = new PassportNumber();
        $violation = $rule->validate('passport', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new PassportNumber();
        self::assertNull($rule->validate('passport', null, []));
    }

    #[Test]
    public function nameReturnsPassportNumber(): void
    {
        $rule = new PassportNumber();
        self::assertSame('passport_number', $rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new PassportNumber(message: 'Custom passport message');
        $violation = $rule->validate('passport', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom passport message', $violation->message);
    }
}
