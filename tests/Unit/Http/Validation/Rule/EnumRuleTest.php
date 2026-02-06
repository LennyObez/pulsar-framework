<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\EnumRule;

enum TestStringEnum: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

enum TestIntEnum: int
{
    case Low = 1;
    case High = 2;
}

#[CoversClass(EnumRule::class)]
final class EnumRuleTest extends TestCase
{
    #[Test]
    public function validStringEnumPasses(): void
    {
        $rule = new EnumRule(TestStringEnum::class);
        self::assertNull($rule->validate('status', 'active', []));
    }

    #[Test]
    public function validIntEnumPasses(): void
    {
        $rule = new EnumRule(TestIntEnum::class);
        self::assertNull($rule->validate('priority', 1, []));
    }

    #[Test]
    public function invalidEnumValueFails(): void
    {
        $rule = new EnumRule(TestStringEnum::class);
        $violation = $rule->validate('status', 'deleted', []);
        self::assertNotNull($violation);
        self::assertSame('enum', $violation->rule);
    }

    #[Test]
    public function wrongTypeForEnumFails(): void
    {
        $rule = new EnumRule(TestIntEnum::class);
        $violation = $rule->validate('priority', 'not-int', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new EnumRule(TestStringEnum::class);
        self::assertNull($rule->validate('status', null, []));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new EnumRule(TestStringEnum::class, message: 'Invalid status.');
        $violation = $rule->validate('status', 'bad', []);
        self::assertNotNull($violation);
        self::assertSame('Invalid status.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('enum', new EnumRule(TestStringEnum::class)->name());
    }
}
