<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\MedicalDevices\Udi;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\MedicalDevices\Udi\UdiIssuingAgency;
use Pulsar\Extension\MedicalDevices\Udi\UdiValidator;

#[CoversClass(UdiValidator::class)]
final class UdiValidatorTest extends TestCase
{
    private UdiValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UdiValidator();
    }

    #[Test]
    public function rejectsEmptyIdentifier(): void
    {
        $result = $this->validator->validate('', UdiIssuingAgency::GS1);

        self::assertFalse($result->valid);
        self::assertSame('Device identifier must not be empty', $result->message);
    }

    #[Test]
    #[DataProvider('validGs1Provider')]
    public function acceptsValidGs1Gtin14(string $gtin): void
    {
        $result = $this->validator->validate($gtin, UdiIssuingAgency::GS1);

        self::assertTrue($result->valid);
    }

    /** @return iterable<string, array{string}> */
    public static function validGs1Provider(): iterable
    {
        // GTIN-14 with valid check digits
        yield 'all zeros' => ['00000000000000'];
        yield 'standard' => ['04012345678901'];
    }

    #[Test]
    #[DataProvider('invalidGs1Provider')]
    public function rejectsInvalidGs1(string $gtin, string $expectedMessage): void
    {
        $result = $this->validator->validate($gtin, UdiIssuingAgency::GS1);

        self::assertFalse($result->valid);
        self::assertStringContainsString($expectedMessage, $result->message ?? '');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidGs1Provider(): iterable
    {
        yield 'too short' => ['040123456789', 'exactly 14 digits'];
        yield 'too long' => ['040123456789012', 'exactly 14 digits'];
        yield 'non-numeric' => ['0401234567890A', 'exactly 14 digits'];
    }

    #[Test]
    public function acceptsValidHibcc(): void
    {
        $result = $this->validator->validate('+ABC123', UdiIssuingAgency::HIBCC);

        self::assertTrue($result->valid);
    }

    #[Test]
    #[DataProvider('invalidHibccProvider')]
    public function rejectsInvalidHibcc(string $di): void
    {
        $result = $this->validator->validate($di, UdiIssuingAgency::HIBCC);

        self::assertFalse($result->valid);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidHibccProvider(): iterable
    {
        yield 'no plus prefix' => ['ABC123'];
        yield 'too short' => ['+AB'];
        yield 'lowercase' => ['+abc'];
    }

    #[Test]
    public function acceptsValidIccbba(): void
    {
        $result = $this->validator->validate('=ABCDEF123456', UdiIssuingAgency::ICCBBA);

        self::assertTrue($result->valid);
    }

    #[Test]
    public function rejectsInvalidIccbba(): void
    {
        $result = $this->validator->validate('ABCDEF', UdiIssuingAgency::ICCBBA);

        self::assertFalse($result->valid);
    }

    #[Test]
    public function acceptsValidIfa(): void
    {
        $result = $this->validator->validate('1234ABCD', UdiIssuingAgency::IFA);

        self::assertTrue($result->valid);
    }

    #[Test]
    public function rejectsInvalidIfa(): void
    {
        $result = $this->validator->validate('AB', UdiIssuingAgency::IFA);

        self::assertFalse($result->valid);
    }
}
