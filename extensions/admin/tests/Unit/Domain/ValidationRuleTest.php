<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Domain\ValidationRule;

final class ValidationRuleTest extends TestCase
{
    #[Test]
    public function required_passes_for_non_empty(): void
    {
        $rule = new ValidationRule('required');

        self::assertNull($rule->validate('hello', 'name'));
        self::assertNull($rule->validate(0, 'count'));
        self::assertNull($rule->validate(false, 'flag'));
    }

    #[Test]
    public function required_fails_for_null(): void
    {
        $rule = new ValidationRule('required');

        self::assertNotNull($rule->validate(null, 'name'));
    }

    #[Test]
    public function required_fails_for_empty_string(): void
    {
        $rule = new ValidationRule('required');

        self::assertNotNull($rule->validate('', 'name'));
    }

    #[Test]
    public function required_custom_message(): void
    {
        $rule = new ValidationRule('required', message: 'Fill this in');

        self::assertSame('Fill this in', $rule->validate(null, 'name'));
    }

    #[Test]
    public function min_length_passes_for_sufficient_length(): void
    {
        $rule = new ValidationRule('min_length', parameter: 3);

        self::assertNull($rule->validate('abc', 'name'));
        self::assertNull($rule->validate('abcdef', 'name'));
    }

    #[Test]
    public function min_length_fails_for_short_string(): void
    {
        $rule = new ValidationRule('min_length', parameter: 5);

        self::assertNotNull($rule->validate('ab', 'name'));
    }

    #[Test]
    public function min_length_ignores_non_string(): void
    {
        $rule = new ValidationRule('min_length', parameter: 3);

        self::assertNull($rule->validate(42, 'count'));
    }

    #[Test]
    public function max_length_passes_for_short_string(): void
    {
        $rule = new ValidationRule('max_length', parameter: 10);

        self::assertNull($rule->validate('hello', 'name'));
    }

    #[Test]
    public function max_length_fails_for_long_string(): void
    {
        $rule = new ValidationRule('max_length', parameter: 3);

        self::assertNotNull($rule->validate('toolong', 'name'));
    }

    #[Test]
    public function min_passes_for_sufficient_value(): void
    {
        $rule = new ValidationRule('min', parameter: 10);

        self::assertNull($rule->validate(10, 'age'));
        self::assertNull($rule->validate(100, 'age'));
    }

    #[Test]
    public function min_fails_for_insufficient_value(): void
    {
        $rule = new ValidationRule('min', parameter: 10);

        self::assertNotNull($rule->validate(5, 'age'));
    }

    #[Test]
    public function max_passes_for_small_value(): void
    {
        $rule = new ValidationRule('max', parameter: 100);

        self::assertNull($rule->validate(50, 'price'));
    }

    #[Test]
    public function max_fails_for_excessive_value(): void
    {
        $rule = new ValidationRule('max', parameter: 100);

        self::assertNotNull($rule->validate(150, 'price'));
    }

    #[Test]
    public function pattern_validates_regex(): void
    {
        $rule = new ValidationRule('pattern', parameter: '/^[A-Z]{3}$/');

        self::assertNull($rule->validate('ABC', 'code'));
        self::assertNotNull($rule->validate('ab', 'code'));
    }

    #[Test]
    public function pattern_ignores_non_string(): void
    {
        $rule = new ValidationRule('pattern', parameter: '/^\d+$/');

        self::assertNull($rule->validate(42, 'num'));
    }

    #[Test]
    public function email_validates_format(): void
    {
        $rule = new ValidationRule('email');

        self::assertNull($rule->validate('user@example.com', 'email'));
        self::assertNotNull($rule->validate('not-an-email', 'email'));
    }

    #[Test]
    public function email_allows_empty_string(): void
    {
        $rule = new ValidationRule('email');

        self::assertNull($rule->validate('', 'email'));
    }

    #[Test]
    public function url_validates_format(): void
    {
        $rule = new ValidationRule('url');

        self::assertNull($rule->validate('https://example.com', 'website'));
        self::assertNotNull($rule->validate('not-a-url', 'website'));
    }

    #[Test]
    public function url_allows_empty_string(): void
    {
        $rule = new ValidationRule('url');

        self::assertNull($rule->validate('', 'website'));
    }

    #[Test]
    public function unknown_rule_returns_null(): void
    {
        $rule = new ValidationRule('custom_rule');

        self::assertNull($rule->validate('anything', 'field'));
    }

    #[Test]
    public function construction_stores_parameters(): void
    {
        $rule = new ValidationRule('min_length', 'Too short', 5);

        self::assertSame('min_length', $rule->rule);
        self::assertSame('Too short', $rule->message);
        self::assertSame(5, $rule->parameter);
    }
}
