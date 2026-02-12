<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\RequiredUnless;

#[CoversClass(RequiredUnless::class)]
final class RequiredUnlessTest extends TestCase
{
    #[Test]
    public function passesWhenConditionMetAndValueNull(): void
    {
        $rule = new RequiredUnless('role', 'admin');
        self::assertNull($rule->validate('reason', null, ['role' => 'admin']));
    }

    #[Test]
    public function failsWhenConditionNotMetAndValueNull(): void
    {
        $rule = new RequiredUnless('role', 'admin');
        $violation = $rule->validate('reason', null, ['role' => 'user']);
        self::assertNotNull($violation);
        self::assertSame('required_unless', $violation->rule);
    }

    #[Test]
    public function failsWhenConditionNotMetAndValueEmpty(): void
    {
        $rule = new RequiredUnless('role', 'admin');
        $violation = $rule->validate('reason', '', ['role' => 'user']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function passesWhenConditionNotMetAndValuePresent(): void
    {
        $rule = new RequiredUnless('role', 'admin');
        self::assertNull($rule->validate('reason', 'some reason', ['role' => 'user']));
    }

    #[Test]
    public function failsWhenOtherFieldMissing(): void
    {
        $rule = new RequiredUnless('role', 'admin');
        $violation = $rule->validate('reason', null, []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function strictComparisonForCondition(): void
    {
        $rule = new RequiredUnless('flag', true);
        $violation = $rule->validate('reason', null, ['flag' => 'true']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function customMessage(): void
    {
        $rule = new RequiredUnless('role', 'admin', message: 'Needed.');
        $violation = $rule->validate('reason', null, ['role' => 'user']);
        self::assertNotNull($violation);
        self::assertSame('Needed.', $violation->message);
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('required_unless', new RequiredUnless('a', 'b')->name());
    }
}
