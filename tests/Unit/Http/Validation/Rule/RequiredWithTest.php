<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\RequiredWith;

#[CoversClass(RequiredWith::class)]
final class RequiredWithTest extends TestCase
{
    #[Test]
    public function passesWhenOtherFieldPresentAndValuePresent(): void
    {
        $rule = new RequiredWith('first_name');
        self::assertNull($rule->validate('last_name', 'Doe', ['first_name' => 'John']));
    }

    #[Test]
    public function failsWhenOtherFieldPresentAndValueNull(): void
    {
        $rule = new RequiredWith('first_name');
        $violation = $rule->validate('last_name', null, ['first_name' => 'John']);
        self::assertNotNull($violation);
        self::assertSame('required_with', $violation->rule);
    }

    #[Test]
    public function failsWhenOtherFieldPresentAndValueEmpty(): void
    {
        $rule = new RequiredWith('first_name');
        $violation = $rule->validate('last_name', '', ['first_name' => 'John']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function passesWhenNoOtherFieldsPresent(): void
    {
        $rule = new RequiredWith('first_name');
        self::assertNull($rule->validate('last_name', null, []));
    }

    #[Test]
    public function passesWhenOtherFieldIsNull(): void
    {
        $rule = new RequiredWith('first_name');
        self::assertNull($rule->validate('last_name', null, ['first_name' => null]));
    }

    #[Test]
    public function failsWhenAnyOfMultipleFieldsPresent(): void
    {
        $rule = new RequiredWith('email', 'phone');
        $violation = $rule->validate('name', null, ['phone' => '+1234567890']);
        self::assertNotNull($violation);
    }

    #[Test]
    public function passesWhenNoneOfMultipleFieldsPresent(): void
    {
        $rule = new RequiredWith('email', 'phone');
        self::assertNull($rule->validate('name', null, []));
    }

    #[Test]
    public function nameReturnsCorrectValue(): void
    {
        self::assertSame('required_with', new RequiredWith('a')->name());
    }
}
