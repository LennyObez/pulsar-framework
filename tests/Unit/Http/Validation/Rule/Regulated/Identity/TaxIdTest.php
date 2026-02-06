<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Identity\TaxId;

#[CoversClass(TaxId::class)]
final class TaxIdTest extends TestCase
{
    #[Test]
    public function usSsnFormatPasses(): void
    {
        $rule = new TaxId(jurisdiction: 'US');
        self::assertNull($rule->validate('tax_id', '123-45-6789', []));
    }

    #[Test]
    public function usEinFormatPasses(): void
    {
        $rule = new TaxId(jurisdiction: 'US');
        self::assertNull($rule->validate('tax_id', '12-3456789', []));
    }

    #[Test]
    public function usItinFormatPasses(): void
    {
        $rule = new TaxId(jurisdiction: 'US');
        self::assertNull($rule->validate('tax_id', '912-34-5678', []));
    }

    #[Test]
    public function gbNationalInsurancePasses(): void
    {
        $rule = new TaxId(jurisdiction: 'GB');
        self::assertNull($rule->validate('tax_id', 'AB123456C', []));
    }

    #[Test]
    public function caSocialInsurancePasses(): void
    {
        $rule = new TaxId(jurisdiction: 'CA');
        self::assertNull($rule->validate('tax_id', '123-456-789', []));
    }

    #[Test]
    public function deFormatPasses(): void
    {
        $rule = new TaxId(jurisdiction: 'DE');
        self::assertNull($rule->validate('tax_id', '12345678901', []));
    }

    #[Test]
    public function frFormatPasses(): void
    {
        $rule = new TaxId(jurisdiction: 'FR');
        self::assertNull($rule->validate('tax_id', '1234567890123', []));
    }

    #[Test]
    public function defaultJurisdictionIsUs(): void
    {
        $rule = new TaxId();
        self::assertNull($rule->validate('tax_id', '123-45-6789', []));
    }

    #[Test]
    public function invalidUsFormatFails(): void
    {
        $rule = new TaxId(jurisdiction: 'US');
        $violation = $rule->validate('tax_id', '12345', []);
        self::assertNotNull($violation);
        self::assertSame('tax_id', $violation->rule);
    }

    #[Test]
    public function unknownJurisdictionUsesGenericPattern(): void
    {
        $rule = new TaxId(jurisdiction: 'XX');
        self::assertNull($rule->validate('tax_id', 'TAX12345', []));
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $rule = new TaxId();
        $violation = $rule->validate('tax_id', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new TaxId();
        self::assertNull($rule->validate('tax_id', null, []));
    }

    #[Test]
    public function nameReturnsTaxId(): void
    {
        $rule = new TaxId();
        self::assertSame('tax_id', $rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new TaxId(message: 'Custom tax ID message');
        $violation = $rule->validate('tax_id', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom tax ID message', $violation->message);
    }

    #[Test]
    public function gbInvalidPrefixFails(): void
    {
        $rule = new TaxId(jurisdiction: 'GB');
        $violation = $rule->validate('tax_id', 'DA123456C', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function caWrongFormatFails(): void
    {
        $rule = new TaxId(jurisdiction: 'CA');
        $violation = $rule->validate('tax_id', '123456789', []);
        self::assertNotNull($violation);
    }
}
