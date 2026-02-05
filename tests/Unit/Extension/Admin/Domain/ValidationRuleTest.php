<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Admin\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ValidationRule;

#[CoversClass(ValidationRule::class)]
final class ValidationRuleTest extends TestCase
{
    #[Test]
    public function requiredFailsOnNull(): void
    {
        $rule = new ValidationRule('required');

        $error = $rule->validate(null, 'name');

        self::assertSame('name is required', $error);
    }

    #[Test]
    public function requiredFailsOnEmptyString(): void
    {
        $rule = new ValidationRule('required');

        $error = $rule->validate('', 'email');

        self::assertSame('email is required', $error);
    }

    #[Test]
    public function requiredPassesWithValue(): void
    {
        $rule = new ValidationRule('required');

        self::assertNull($rule->validate('John', 'name'));
    }

    #[Test]
    public function requiredUsesCustomMessage(): void
    {
        $rule = new ValidationRule('required', 'Please provide your name');

        $error = $rule->validate(null, 'name');

        self::assertSame('Please provide your name', $error);
    }

    #[Test]
    public function minLengthFailsWhenTooShort(): void
    {
        $rule = new ValidationRule('min_length', null, 3);

        $error = $rule->validate('ab', 'name');

        self::assertSame('name must be at least 3 characters', $error);
    }

    #[Test]
    public function minLengthPassesWhenExactLength(): void
    {
        $rule = new ValidationRule('min_length', null, 3);

        self::assertNull($rule->validate('abc', 'name'));
    }

    #[Test]
    public function minLengthPassesWhenLonger(): void
    {
        $rule = new ValidationRule('min_length', null, 3);

        self::assertNull($rule->validate('abcdef', 'name'));
    }

    #[Test]
    public function minLengthSkipsNonString(): void
    {
        $rule = new ValidationRule('min_length', null, 3);

        self::assertNull($rule->validate(42, 'count'));
    }

    #[Test]
    public function maxLengthFailsWhenTooLong(): void
    {
        $rule = new ValidationRule('max_length', null, 5);

        $error = $rule->validate('toolong', 'code');

        self::assertSame('code must not exceed 5 characters', $error);
    }

    #[Test]
    public function maxLengthPassesWhenExactLength(): void
    {
        $rule = new ValidationRule('max_length', null, 5);

        self::assertNull($rule->validate('12345', 'code'));
    }

    #[Test]
    public function maxLengthPassesWhenShorter(): void
    {
        $rule = new ValidationRule('max_length', null, 10);

        self::assertNull($rule->validate('abc', 'code'));
    }

    #[Test]
    public function maxLengthSkipsNonString(): void
    {
        $rule = new ValidationRule('max_length', null, 5);

        self::assertNull($rule->validate(123456, 'value'));
    }

    #[Test]
    public function minFailsWhenBelowMinimum(): void
    {
        $rule = new ValidationRule('min', null, 10);

        $error = $rule->validate(5, 'age');

        self::assertSame('age must be at least 10', $error);
    }

    #[Test]
    public function minPassesWhenAtMinimum(): void
    {
        $rule = new ValidationRule('min', null, 10);

        self::assertNull($rule->validate(10, 'age'));
    }

    #[Test]
    public function minPassesWhenAboveMinimum(): void
    {
        $rule = new ValidationRule('min', null, 10);

        self::assertNull($rule->validate(20, 'age'));
    }

    #[Test]
    public function minSkipsNonNumeric(): void
    {
        $rule = new ValidationRule('min', null, 10);

        self::assertNull($rule->validate('abc', 'age'));
    }

    #[Test]
    public function maxFailsWhenAboveMaximum(): void
    {
        $rule = new ValidationRule('max', null, 100);

        $error = $rule->validate(150, 'score');

        self::assertSame('score must not exceed 100', $error);
    }

    #[Test]
    public function maxPassesWhenAtMaximum(): void
    {
        $rule = new ValidationRule('max', null, 100);

        self::assertNull($rule->validate(100, 'score'));
    }

    #[Test]
    public function maxPassesWhenBelowMaximum(): void
    {
        $rule = new ValidationRule('max', null, 100);

        self::assertNull($rule->validate(50, 'score'));
    }

    #[Test]
    public function patternFailsWhenNoMatch(): void
    {
        $rule = new ValidationRule('pattern', null, '/^[A-Z]{2}-\d{4}$/');

        $error = $rule->validate('invalid', 'code');

        self::assertSame('code format is invalid', $error);
    }

    #[Test]
    public function patternPassesWhenMatches(): void
    {
        $rule = new ValidationRule('pattern', null, '/^[A-Z]{2}-\d{4}$/');

        self::assertNull($rule->validate('AB-1234', 'code'));
    }

    #[Test]
    public function patternSkipsNonString(): void
    {
        $rule = new ValidationRule('pattern', null, '/^\d+$/');

        self::assertNull($rule->validate(42, 'value'));
    }

    #[Test]
    public function emailFailsForInvalidEmail(): void
    {
        $rule = new ValidationRule('email');

        $error = $rule->validate('not-an-email', 'email');

        self::assertSame('email must be a valid email address', $error);
    }

    #[Test]
    public function emailPassesForValidEmail(): void
    {
        $rule = new ValidationRule('email');

        self::assertNull($rule->validate('user@example.com', 'email'));
    }

    #[Test]
    public function emailPassesForEmptyString(): void
    {
        $rule = new ValidationRule('email');

        self::assertNull($rule->validate('', 'email'));
    }

    #[Test]
    public function emailSkipsNonString(): void
    {
        $rule = new ValidationRule('email');

        self::assertNull($rule->validate(42, 'email'));
    }

    #[Test]
    public function urlFailsForInvalidUrl(): void
    {
        $rule = new ValidationRule('url');

        $error = $rule->validate('not-a-url', 'website');

        self::assertSame('website must be a valid URL', $error);
    }

    #[Test]
    public function urlPassesForValidUrl(): void
    {
        $rule = new ValidationRule('url');

        self::assertNull($rule->validate('https://example.com', 'website'));
    }

    #[Test]
    public function urlPassesForEmptyString(): void
    {
        $rule = new ValidationRule('url');

        self::assertNull($rule->validate('', 'website'));
    }

    #[Test]
    public function urlSkipsNonString(): void
    {
        $rule = new ValidationRule('url');

        self::assertNull($rule->validate(42, 'website'));
    }

    #[Test]
    public function unknownRuleReturnsNull(): void
    {
        $rule = new ValidationRule('custom_rule');

        self::assertNull($rule->validate('anything', 'field'));
    }

    #[Test]
    public function urlUsesCustomMessage(): void
    {
        $rule = new ValidationRule('url', 'Enter a proper URL');

        $error = $rule->validate('invalid', 'website');

        self::assertSame('Enter a proper URL', $error);
    }

    #[Test]
    public function emailUsesCustomMessage(): void
    {
        $rule = new ValidationRule('email', 'Bad email format');

        $error = $rule->validate('notvalid', 'email');

        self::assertSame('Bad email format', $error);
    }

    #[Test]
    public function minWithFloatValues(): void
    {
        $rule = new ValidationRule('min', null, 1.5);

        self::assertNotNull($rule->validate(1.0, 'rate'));
        self::assertNull($rule->validate(2.0, 'rate'));
    }

    #[Test]
    public function maxWithFloatValues(): void
    {
        $rule = new ValidationRule('max', null, 99.9);

        self::assertNotNull($rule->validate(100.0, 'percent'));
        self::assertNull($rule->validate(50.5, 'percent'));
    }
}
