<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Healthcare;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Healthcare\Hl7Date;

#[CoversClass(Hl7Date::class)]
final class Hl7DateTest extends TestCase
{
    private Hl7Date $rule;

    protected function setUp(): void
    {
        $this->rule = new Hl7Date();
    }

    #[Test]
    public function yearOnlyPasses(): void
    {
        self::assertNull($this->rule->validate('date', '2024', []));
    }

    #[Test]
    public function yearMonthPasses(): void
    {
        self::assertNull($this->rule->validate('date', '202401', []));
    }

    #[Test]
    public function yearMonthDayPasses(): void
    {
        self::assertNull($this->rule->validate('date', '20240115', []));
    }

    #[Test]
    public function fullDateTimePasses(): void
    {
        self::assertNull($this->rule->validate('date', '20240115103045', []));
    }

    #[Test]
    public function dateTimeWithFractionPasses(): void
    {
        self::assertNull($this->rule->validate('date', '20240115103045.1234', []));
    }

    #[Test]
    public function dateTimeWithTimezonePasses(): void
    {
        self::assertNull($this->rule->validate('date', '20240115103045+0500', []));
    }

    #[Test]
    public function dateTimeWithNegativeTimezonePasses(): void
    {
        self::assertNull($this->rule->validate('date', '20240115103045-0800', []));
    }

    #[Test]
    public function dateTimeWithFractionAndTimezonePasses(): void
    {
        self::assertNull($this->rule->validate('date', '20240115103045.12+0500', []));
    }

    #[Test]
    public function invalidMonthFails(): void
    {
        $violation = $this->rule->validate('date', '202413', []);
        self::assertNotNull($violation);
        self::assertSame('hl7_date', $violation->rule);
    }

    #[Test]
    public function invalidDayFails(): void
    {
        $violation = $this->rule->validate('date', '20240132', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function invalidHourFails(): void
    {
        $violation = $this->rule->validate('date', '2024011525', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function invalidMinuteFails(): void
    {
        $violation = $this->rule->validate('date', '202401151061', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonNumericFails(): void
    {
        $violation = $this->rule->validate('date', 'abcdefgh', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('date', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('date', null, []));
    }

    #[Test]
    public function nameReturnsHl7Date(): void
    {
        self::assertSame('hl7_date', $this->rule->name());
    }

    #[Test]
    public function monthZeroFails(): void
    {
        $violation = $this->rule->validate('date', '202400', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function dayZeroFails(): void
    {
        $violation = $this->rule->validate('date', '20240100', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Hl7Date(message: 'Custom HL7 message');
        $violation = $rule->validate('date', 'not-a-date', []);
        self::assertNotNull($violation);
        self::assertSame('Custom HL7 message', $violation->message);
    }

    #[Test]
    public function yearWithTimezonePasses(): void
    {
        self::assertNull($this->rule->validate('date', '2024+0500', []));
    }

    #[Test]
    public function threeDigitYearFails(): void
    {
        $violation = $this->rule->validate('date', '202', []);
        self::assertNotNull($violation);
    }
}
