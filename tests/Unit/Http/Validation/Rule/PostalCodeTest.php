<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\PostalCode;

#[CoversClass(PostalCode::class)]
final class PostalCodeTest extends TestCase
{
    #[Test]
    public function validUsZipPasses(): void
    {
        $rule = new PostalCode('US');
        self::assertNull($rule->validate('zip', '90210', []));
    }

    #[Test]
    public function validUsZipPlusFourPasses(): void
    {
        $rule = new PostalCode('US');
        self::assertNull($rule->validate('zip', '90210-1234', []));
    }

    #[Test]
    public function invalidUsZipFails(): void
    {
        $rule = new PostalCode('US');
        $violation = $rule->validate('zip', '9021', []);
        self::assertNotNull($violation);
        self::assertSame('postal_code', $violation->rule);
    }

    #[Test]
    public function validCanadianPostalCodePasses(): void
    {
        $rule = new PostalCode('CA');
        self::assertNull($rule->validate('postal', 'K1A 0B1', []));
    }

    #[Test]
    public function validGermanPostalCodePasses(): void
    {
        $rule = new PostalCode('DE');
        self::assertNull($rule->validate('plz', '10115', []));
    }

    #[Test]
    public function validUkPostcodePasses(): void
    {
        $rule = new PostalCode('GB');
        self::assertNull($rule->validate('postcode', 'SW1A 1AA', []));
    }

    #[Test]
    public function validJapanesePostalCodePasses(): void
    {
        $rule = new PostalCode('JP');
        self::assertNull($rule->validate('postal', '100-0001', []));
    }

    #[Test]
    public function unsupportedCountryFails(): void
    {
        $rule = new PostalCode('ZZ');
        $violation = $rule->validate('postal', '12345', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function defaultCountryIsUs(): void
    {
        $rule = new PostalCode();
        self::assertNull($rule->validate('zip', '90210', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new PostalCode('US');
        self::assertNull($rule->validate('zip', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new PostalCode('US', message: 'Bad ZIP.');
        $violation = $rule->validate('zip', 'abc', []);
        self::assertNotNull($violation);
        self::assertSame('Bad ZIP.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('postal_code', new PostalCode()->name());
    }
}
