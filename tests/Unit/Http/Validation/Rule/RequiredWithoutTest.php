<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\RequiredWithout;

#[CoversClass(RequiredWithout::class)]
final class RequiredWithoutTest extends TestCase
{
    #[Test]
    public function passesWhenOtherFieldAbsentAndValuePresent(): void
    {
        $rule = new RequiredWithout('email');
        self::assertNull($rule->validate('phone', '+1234567890', []));
    }

    #[Test]
    public function failsWhenOtherFieldAbsentAndValueNull(): void
    {
        $rule = new RequiredWithout('email');
        $violation = $rule->validate('phone', null, []);
        self::assertNotNull($violation);
        self::assertSame('required_without', $violation->rule);
    }

    #[Test]
    public function failsWhenOtherFieldAbsentAndValueEmpty(): void
    {
        $rule = new RequiredWithout('email');
        $violation = $rule->validate('phone', '', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function passesWhenOtherFieldPresent(): void
    {
        $rule = new RequiredWithout('email');
        self::assertNull($rule->validate('phone', null, ['email' => 'test@example.com']));
    }

    #[Test]
    public function failsWhenOtherFieldNull(): void
    {
        $rule = new RequiredWithout('email');
        $violation = $rule->validate('phone', null, ['email' => null]);
        self::assertNotNull($violation);
    }

    #[Test]
    public function failsWhenAnyOfMultipleFieldsMissing(): void
    {
        $rule = new RequiredWithout('email', 'phone');
        $violation = $rule->validate('name', null, ['email' => 'test@example.com']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function passesWhenAllFieldsPresent(): void
    {
        $rule = new RequiredWithout('email', 'phone');
        self::assertNull($rule->validate('name', null, [
            'email' => 'test@example.com',
            'phone' => '+1234567890',
        ]));
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('required_without', new RequiredWithout('a')->name());
    }
}
