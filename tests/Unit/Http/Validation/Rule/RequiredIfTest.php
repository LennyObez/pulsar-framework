<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\RequiredIf;

#[CoversClass(RequiredIf::class)]
final class RequiredIfTest extends TestCase
{
    #[Test]
    public function requiredWhenConditionMetAndValuePresent(): void
    {
        $rule = new RequiredIf('role', 'admin');
        self::assertNull($rule->validate('reason', 'justified', ['role' => 'admin']));
    }

    #[Test]
    public function failsWhenConditionMetAndValueNull(): void
    {
        $rule = new RequiredIf('role', 'admin');
        $violation = $rule->validate('reason', null, ['role' => 'admin']);
        self::assertNotNull($violation);
        self::assertSame('required_if', $violation->rule);
    }

    #[Test]
    public function failsWhenConditionMetAndValueEmpty(): void
    {
        $rule = new RequiredIf('role', 'admin');
        $violation = $rule->validate('reason', '', ['role' => 'admin']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function failsWhenConditionMetAndValueEmptyArray(): void
    {
        $rule = new RequiredIf('role', 'admin');
        $violation = $rule->validate('reason', [], ['role' => 'admin']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function passesWhenConditionNotMet(): void
    {
        $rule = new RequiredIf('role', 'admin');
        self::assertNull($rule->validate('reason', null, ['role' => 'user']));
    }

    #[Test]
    public function passesWhenOtherFieldMissing(): void
    {
        $rule = new RequiredIf('role', 'admin');
        self::assertNull($rule->validate('reason', null, []));
    }

    #[Test]
    public function strictComparisonForCondition(): void
    {
        $rule = new RequiredIf('flag', true);
        self::assertNull($rule->validate('reason', null, ['flag' => 'true']));
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new RequiredIf('role', 'admin', message: 'Reason needed.');
        $violation = $rule->validate('reason', null, ['role' => 'admin']);
        self::assertNotNull($violation);
        self::assertSame('Reason needed.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('required_if', new RequiredIf('a', 'b')->name());
    }
}
