<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule\Regulated\Financial;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Regulated\Financial\ExpirationDate;

#[CoversClass(ExpirationDate::class)]
final class ExpirationDateTest extends TestCase
{
    private ExpirationDate $rule;

    protected function setUp(): void
    {
        $this->rule = new ExpirationDate();
    }

    #[Test]
    public function validTwoDigitYearPasses(): void
    {
        self::assertNull($this->rule->validate('expiry', '12/25', []));
    }

    #[Test]
    public function validFourDigitYearPasses(): void
    {
        self::assertNull($this->rule->validate('expiry', '01/2025', []));
    }

    #[Test]
    public function januaryPasses(): void
    {
        self::assertNull($this->rule->validate('expiry', '01/30', []));
    }

    #[Test]
    public function decemberPasses(): void
    {
        self::assertNull($this->rule->validate('expiry', '12/30', []));
    }

    #[Test]
    public function monthThirteenFails(): void
    {
        $violation = $this->rule->validate('expiry', '13/25', []);
        self::assertNotNull($violation);
        self::assertSame('expiration_date', $violation->rule);
    }

    #[Test]
    public function monthZeroFails(): void
    {
        $violation = $this->rule->validate('expiry', '00/25', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function noSlashFails(): void
    {
        $violation = $this->rule->validate('expiry', '1225', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function threeDigitYearFails(): void
    {
        $violation = $this->rule->validate('expiry', '12/202', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('expiry', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('expiry', null, []));
    }

    #[Test]
    public function nameReturnsExpirationDate(): void
    {
        self::assertSame('expiration_date', $this->rule->name());
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new ExpirationDate(message: 'Custom expiry message');
        $violation = $rule->validate('expiry', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Custom expiry message', $violation->message);
    }

    #[Test]
    public function dashSeparatorFails(): void
    {
        $violation = $this->rule->validate('expiry', '12-25', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function singleDigitMonthFails(): void
    {
        $violation = $this->rule->validate('expiry', '1/25', []);
        self::assertNotNull($violation);
    }
}
