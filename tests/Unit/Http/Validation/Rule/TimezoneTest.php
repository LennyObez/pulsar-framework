<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Timezone;

#[CoversClass(Timezone::class)]
final class TimezoneTest extends TestCase
{
    private Timezone $rule;

    protected function setUp(): void
    {
        $this->rule = new Timezone();
    }

    #[Test]
    public function validTimezonePasses(): void
    {
        self::assertNull($this->rule->validate('field', 'America/New_York', []));
    }

    #[Test]
    public function utcTimezonePasses(): void
    {
        self::assertNull($this->rule->validate('field', 'UTC', []));
    }

    #[Test]
    public function europeLondonPasses(): void
    {
        self::assertNull($this->rule->validate('field', 'Europe/London', []));
    }

    #[Test]
    public function invalidTimezoneFails(): void
    {
        $violation = $this->rule->validate('field', 'Invalid/Timezone', []);
        self::assertNotNull($violation);
        self::assertSame('timezone', $violation->rule);
    }

    #[Test]
    public function emptyStringFails(): void
    {
        $violation = $this->rule->validate('field', '', []);
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
        $rule = new Timezone(message: 'Bad timezone');
        $violation = $rule->validate('field', 'Nope', []);
        self::assertNotNull($violation);
        self::assertSame('Bad timezone', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $violation = $this->rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function numericOffsetFails(): void
    {
        $violation = $this->rule->validate('field', '+05:30', []);
        self::assertNotNull($violation);
    }
}
