<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Tests\Unit\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\UdiIssuingAgency;
use Pulsar\Extension\MedicalDevices\Udi\UdiValidationResult;
use Pulsar\Extension\MedicalDevices\Udi\UdiValidator;

#[CoversClass(UdiValidator::class)]
#[CoversClass(UdiValidationResult::class)]
final class UdiValidatorTest extends TestCase
{
    private UdiValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UdiValidator();
    }

    #[Test]
    public function emptyIdentifierIsInvalidForAllAgencies(): void
    {
        foreach (UdiIssuingAgency::cases() as $agency) {
            $result = $this->validator->validate('', $agency);
            self::assertFalse($result->valid, "Empty DI should be invalid for {$agency->value}");
            self::assertSame('Device identifier must not be empty', $result->message);
        }
    }

    #[Test]
    public function gs1ValidGtin14Passes(): void
    {
        // Valid GTIN-14 with correct check digit: 00012345678905
        $result = $this->validator->validate('00012345678905', UdiIssuingAgency::GS1);
        self::assertTrue($result->valid);
        self::assertNull($result->message);
    }

    #[Test]
    public function gs1TooShortFails(): void
    {
        $result = $this->validator->validate('1234567890', UdiIssuingAgency::GS1);
        self::assertFalse($result->valid);
        self::assertStringContainsString('14 digits', $result->message ?? '');
    }

    #[Test]
    public function gs1NonNumericFails(): void
    {
        $result = $this->validator->validate('0001234567890A', UdiIssuingAgency::GS1);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function gs1InvalidCheckDigitFails(): void
    {
        // 00012345678905 is valid; changing last digit to 6 makes it invalid
        $result = $this->validator->validate('00012345678906', UdiIssuingAgency::GS1);
        self::assertFalse($result->valid);
        self::assertStringContainsString('check digit', $result->message ?? '');
    }

    #[Test]
    public function hibccValidIdentifierPasses(): void
    {
        $result = $this->validator->validate('+ABC123', UdiIssuingAgency::HIBCC);
        self::assertTrue($result->valid);
    }

    #[Test]
    public function hibccMissingPlusFails(): void
    {
        $result = $this->validator->validate('ABC123', UdiIssuingAgency::HIBCC);
        self::assertFalse($result->valid);
        self::assertStringContainsString('+', $result->message ?? '');
    }

    #[Test]
    public function hibccTooShortFails(): void
    {
        $result = $this->validator->validate('+AB', UdiIssuingAgency::HIBCC);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function iccbbaValidIdentifierPasses(): void
    {
        $result = $this->validator->validate('=ABCDEF', UdiIssuingAgency::ICCBBA);
        self::assertTrue($result->valid);
    }

    #[Test]
    public function iccbbaMissingEqualsFails(): void
    {
        $result = $this->validator->validate('ABCDEF', UdiIssuingAgency::ICCBBA);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function iccbbaTooShortFails(): void
    {
        $result = $this->validator->validate('=ABC', UdiIssuingAgency::ICCBBA);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function ifaValidIdentifierPasses(): void
    {
        $result = $this->validator->validate('AB12', UdiIssuingAgency::IFA);
        self::assertTrue($result->valid);
    }

    #[Test]
    public function ifaTooShortFails(): void
    {
        $result = $this->validator->validate('AB1', UdiIssuingAgency::IFA);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function ifaLowercaseFails(): void
    {
        $result = $this->validator->validate('ab12', UdiIssuingAgency::IFA);
        self::assertFalse($result->valid);
    }

    #[Test]
    public function validResultHasNoMessage(): void
    {
        $result = new UdiValidationResult(true);
        self::assertTrue($result->valid);
        self::assertNull($result->message);
    }

    #[Test]
    public function invalidResultCarriesMessage(): void
    {
        $result = new UdiValidationResult(false, 'Something wrong');
        self::assertFalse($result->valid);
        self::assertSame('Something wrong', $result->message);
    }

    /**
     * @return iterable<string, array{UdiIssuingAgency, string}>
     */
    public static function agencyProvider(): iterable
    {
        yield 'gs1' => [UdiIssuingAgency::GS1, 'gs1'];
        yield 'hibcc' => [UdiIssuingAgency::HIBCC, 'hibcc'];
        yield 'iccbba' => [UdiIssuingAgency::ICCBBA, 'iccbba'];
        yield 'ifa' => [UdiIssuingAgency::IFA, 'ifa'];
    }

    #[Test]
    #[DataProvider('agencyProvider')]
    public function issuingAgencyEnumValues(UdiIssuingAgency $agency, string $expected): void
    {
        self::assertSame($expected, $agency->value);
    }
}
