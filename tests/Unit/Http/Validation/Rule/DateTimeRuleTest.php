<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\DateTimeRule;

#[CoversClass(DateTimeRule::class)]
final class DateTimeRuleTest extends TestCase
{
    private DateTimeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new DateTimeRule();
    }

    #[Test]
    public function validDatetimePasses(): void
    {
        self::assertNull($this->rule->validate('field', '2024-01-15 14:30:00', []));
    }

    #[Test]
    public function invalidDatetimeFails(): void
    {
        $violation = $this->rule->validate('field', 'not-a-datetime', []);
        self::assertNotNull($violation);
        self::assertSame('date_time', $violation->rule);
    }

    #[Test]
    public function dateOnlyFails(): void
    {
        $violation = $this->rule->validate('field', '2024-01-15', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customFormatPasses(): void
    {
        $rule = new DateTimeRule(format: 'd/m/Y H:i');
        self::assertNull($rule->validate('field', '15/01/2024 14:30', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        self::assertNull($this->rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new DateTimeRule(message: 'Invalid datetime');
        $violation = $rule->validate('field', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Invalid datetime', $violation->message);
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
