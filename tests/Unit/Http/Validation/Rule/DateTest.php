<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Date;

#[CoversClass(Date::class)]
final class DateTest extends TestCase
{
    private Date $rule;

    protected function setUp(): void
    {
        $this->rule = new Date();
    }

    #[Test]
    public function validDatePasses(): void
    {
        self::assertNull($this->rule->validate('field', '2024-01-15', []));
    }

    #[Test]
    public function invalidDateFails(): void
    {
        $violation = $this->rule->validate('field', 'not-a-date', []);
        self::assertNotNull($violation);
        self::assertSame('date', $violation->rule);
    }

    #[Test]
    public function invalidMonthFails(): void
    {
        $violation = $this->rule->validate('field', '2024-13-01', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function invalidDayFails(): void
    {
        $violation = $this->rule->validate('field', '2024-02-30', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customFormatPasses(): void
    {
        $rule = new Date(format: 'd/m/Y');
        self::assertNull($rule->validate('field', '15/01/2024', []));
    }

    #[Test]
    public function wrongFormatFails(): void
    {
        $violation = $this->rule->validate('field', '15/01/2024', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Date(message: 'Invalid date');
        $violation = $rule->validate('field', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Invalid date', $violation->message);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $violation = $this->rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
