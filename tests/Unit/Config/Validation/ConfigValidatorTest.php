<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Validation\ConfigSeverity;
use Pulsar\Config\Validation\ConfigValidationError;
use Pulsar\Config\Validation\ConfigValidator;

#[CoversClass(ConfigValidator::class)]
#[CoversClass(ConfigValidationError::class)]
#[CoversClass(ConfigSeverity::class)]
final class ConfigValidatorTest extends TestCase
{
    #[Test]
    public function empty_data_with_no_rules_produces_valid_result(): void
    {
        $validator = new ConfigValidator([], 'test');

        $result = $validator->result();

        self::assertTrue($result->isValid());
        self::assertSame(0, $result->errorCount());
    }

    #[Test]
    public function required_fails_when_key_is_missing(): void
    {
        $validator = new ConfigValidator([], 'app');

        $result = $validator->required('name')->result();

        self::assertFalse($result->isValid());
        self::assertSame(1, $result->errorCount());
        self::assertStringContainsString('app.name', $result->messages()[0]);
    }

    #[Test]
    public function required_fails_when_key_is_null(): void
    {
        $validator = new ConfigValidator(['name' => null], 'app');

        $result = $validator->required('name')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function required_passes_when_key_is_present_and_non_null(): void
    {
        $validator = new ConfigValidator(['name' => 'Pulsar'], 'app');

        $result = $validator->required('name')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function string_validation_passes_for_string_value(): void
    {
        $validator = new ConfigValidator(['name' => 'Pulsar'], 'app');

        $result = $validator->string('name')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function string_validation_fails_for_non_string_value(): void
    {
        $validator = new ConfigValidator(['name' => 42], 'app');

        $result = $validator->string('name')->result();

        self::assertFalse($result->isValid());
        self::assertStringContainsString('string', $result->messages()[0]);
    }

    #[Test]
    public function string_validation_skips_missing_keys(): void
    {
        $validator = new ConfigValidator([], 'app');

        $result = $validator->string('name')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function boolean_validation_passes_for_bool_value(): void
    {
        $validator = new ConfigValidator(['debug' => true], 'app');

        $result = $validator->boolean('debug')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function boolean_validation_fails_for_non_bool(): void
    {
        $validator = new ConfigValidator(['debug' => 'yes'], 'app');

        $result = $validator->boolean('debug')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function integer_validation_passes_for_int(): void
    {
        $validator = new ConfigValidator(['port' => 8080], 'server');

        $result = $validator->integer('port')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function integer_validation_fails_for_non_int(): void
    {
        $validator = new ConfigValidator(['port' => '8080'], 'server');

        $result = $validator->integer('port')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function array_validation_passes_for_array(): void
    {
        $validator = new ConfigValidator(['items' => [1, 2, 3]], 'cache');

        $result = $validator->array('items')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function array_validation_fails_for_non_array(): void
    {
        $validator = new ConfigValidator(['items' => 'not-array'], 'cache');

        $result = $validator->array('items')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function int_range_passes_when_in_range(): void
    {
        $validator = new ConfigValidator(['port' => 8080], 'server');

        $result = $validator->intRange('port', 1, 65535)->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function int_range_fails_when_below_min(): void
    {
        $validator = new ConfigValidator(['port' => 0], 'server');

        $result = $validator->intRange('port', 1, 65535)->result();

        self::assertFalse($result->isValid());
        self::assertStringContainsString('between', $result->messages()[0]);
    }

    #[Test]
    public function int_range_fails_when_above_max(): void
    {
        $validator = new ConfigValidator(['port' => 70000], 'server');

        $result = $validator->intRange('port', 1, 65535)->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function positive_int_passes_for_positive_value(): void
    {
        $validator = new ConfigValidator(['count' => 5], 'test');

        $result = $validator->positiveInt('count')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function positive_int_fails_for_zero(): void
    {
        $validator = new ConfigValidator(['count' => 0], 'test');

        $result = $validator->positiveInt('count')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function positive_int_fails_for_negative(): void
    {
        $validator = new ConfigValidator(['count' => -1], 'test');

        $result = $validator->positiveInt('count')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function non_negative_int_passes_for_zero(): void
    {
        $validator = new ConfigValidator(['retries' => 0], 'test');

        $result = $validator->nonNegativeInt('retries')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function non_negative_int_fails_for_negative(): void
    {
        $validator = new ConfigValidator(['retries' => -1], 'test');

        $result = $validator->nonNegativeInt('retries')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function one_of_passes_for_allowed_value(): void
    {
        $validator = new ConfigValidator(['driver' => 'sqlite'], 'db');

        $result = $validator->oneOf('driver', ['sqlite', 'mysql', 'pgsql'])->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function one_of_fails_for_disallowed_value(): void
    {
        $validator = new ConfigValidator(['driver' => 'oracle'], 'db');

        $result = $validator->oneOf('driver', ['sqlite', 'mysql', 'pgsql'])->result();

        self::assertFalse($result->isValid());
        self::assertStringContainsString('oracle', $result->messages()[0]);
    }

    #[Test]
    public function matches_passes_for_matching_pattern(): void
    {
        $validator = new ConfigValidator(['email' => 'test@example.com'], 'user');

        $result = $validator->matches('email', '/@/', 'email address')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function matches_fails_for_non_matching_pattern(): void
    {
        $validator = new ConfigValidator(['email' => 'invalid'], 'user');

        $result = $validator->matches('email', '/@/', 'email address')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function non_empty_string_passes_for_non_empty(): void
    {
        $validator = new ConfigValidator(['name' => 'Pulsar'], 'app');

        $result = $validator->nonEmptyString('name')->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function non_empty_string_fails_for_empty_string(): void
    {
        $validator = new ConfigValidator(['name' => ''], 'app');

        $result = $validator->nonEmptyString('name')->result();

        self::assertFalse($result->isValid());
    }

    #[Test]
    public function multiple_validations_accumulate_errors(): void
    {
        $validator = new ConfigValidator(['port' => 'bad', 'debug' => 'also-bad'], 'app');

        $result = $validator
            ->integer('port')
            ->boolean('debug')
            ->required('name')
            ->result();

        self::assertFalse($result->isValid());
        self::assertSame(3, $result->errorCount());
    }

    #[Test]
    public function add_error_adds_custom_error(): void
    {
        $validator = new ConfigValidator([], 'custom');

        $result = $validator->addError('key', 'Custom message', ConfigSeverity::Warning)->result();

        self::assertFalse($result->isValid());
        self::assertSame('Custom message', $result->messages()[0]);
        self::assertSame(ConfigSeverity::Warning, $result->errors[0]->severity);
    }

    #[Test]
    public function has_errors_returns_true_when_errors_exist(): void
    {
        $validator = new ConfigValidator([], 'test');
        $validator->required('missing');

        self::assertTrue($validator->hasErrors());
    }

    #[Test]
    public function has_errors_returns_false_when_no_errors(): void
    {
        $validator = new ConfigValidator(['key' => 'value'], 'test');
        $validator->string('key');

        self::assertFalse($validator->hasErrors());
    }

    #[Test]
    public function null_values_are_skipped_for_type_checks(): void
    {
        $validator = new ConfigValidator(['key' => null], 'test');

        $result = $validator
            ->string('key')
            ->integer('key')
            ->boolean('key')
            ->array('key')
            ->result();

        self::assertTrue($result->isValid());
    }

    #[Test]
    public function int_range_skips_non_integer_values(): void
    {
        $validator = new ConfigValidator(['port' => 'abc'], 'server');

        $result = $validator->intRange('port', 1, 65535)->result();

        self::assertTrue($result->isValid());
    }
}
