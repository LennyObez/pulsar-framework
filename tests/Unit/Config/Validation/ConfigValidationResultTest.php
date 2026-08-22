<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Validation\ConfigSeverity;
use Pulsar\Config\Validation\ConfigValidationError;
use Pulsar\Config\Validation\ConfigValidationResult;

#[CoversClass(ConfigValidationResult::class)]
final class ConfigValidationResultTest extends TestCase
{
    #[Test]
    public function empty_result_is_valid(): void
    {
        $result = new ConfigValidationResult();

        self::assertTrue($result->isValid());
        self::assertSame(0, $result->errorCount());
        self::assertSame([], $result->messages());
    }

    #[Test]
    public function result_with_errors_is_invalid(): void
    {
        $errors = [
            new ConfigValidationError('app.name', 'Name is required'),
            new ConfigValidationError('app.port', 'Port must be an integer'),
        ];

        $result = new ConfigValidationResult($errors);

        self::assertFalse($result->isValid());
        self::assertSame(2, $result->errorCount());
    }

    #[Test]
    public function messages_returns_error_messages(): void
    {
        $errors = [
            new ConfigValidationError('key1', 'Message one'),
            new ConfigValidationError('key2', 'Message two'),
        ];

        $result = new ConfigValidationResult($errors);

        self::assertSame(['Message one', 'Message two'], $result->messages());
    }

    #[Test]
    public function merge_combines_two_results(): void
    {
        $result1 = new ConfigValidationResult([
            new ConfigValidationError('a', 'Error A'),
        ]);
        $result2 = new ConfigValidationResult([
            new ConfigValidationError('b', 'Error B'),
            new ConfigValidationError('c', 'Error C'),
        ]);

        $merged = $result1->merge($result2);

        self::assertSame(3, $merged->errorCount());
        self::assertSame(['Error A', 'Error B', 'Error C'], $merged->messages());
    }

    #[Test]
    public function merge_with_empty_result_preserves_errors(): void
    {
        $result = new ConfigValidationResult([
            new ConfigValidationError('a', 'Error A'),
        ]);

        $merged = $result->merge(new ConfigValidationResult());

        self::assertSame(1, $merged->errorCount());
    }

    #[Test]
    public function error_preserves_path_and_severity(): void
    {
        $error = new ConfigValidationError('config.key', 'Bad value', ConfigSeverity::Warning);

        self::assertSame('config.key', $error->path);
        self::assertSame('Bad value', $error->message);
        self::assertSame(ConfigSeverity::Warning, $error->severity);
    }

    #[Test]
    public function error_factory_required(): void
    {
        $error = ConfigValidationError::required('app.secret');

        self::assertSame('app.secret', $error->path);
        self::assertStringContainsString('missing', $error->message);
        self::assertSame(ConfigSeverity::Error, $error->severity);
    }

    #[Test]
    public function error_factory_invalid_type(): void
    {
        $error = ConfigValidationError::invalidType('app.port', 'int', 'string');

        self::assertStringContainsString('int', $error->message);
        self::assertStringContainsString('string', $error->message);
    }

    #[Test]
    public function error_factory_out_of_range(): void
    {
        $error = ConfigValidationError::outOfRange('app.port', 'must be 1-65535');

        self::assertStringContainsString('out of range', $error->message);
    }

    #[Test]
    public function error_factory_invalid_format(): void
    {
        $error = ConfigValidationError::invalidFormat('app.url', 'valid URL');

        self::assertStringContainsString('invalid format', $error->message);
    }

    #[Test]
    public function error_factory_custom(): void
    {
        $error = ConfigValidationError::custom('path', 'Custom', ConfigSeverity::Warning);

        self::assertSame('Custom', $error->message);
        self::assertSame(ConfigSeverity::Warning, $error->severity);
    }
}
