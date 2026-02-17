<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Filter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Filter\SanitizationResult;

final class SanitizationResultTest extends TestCase
{
    #[Test]
    public function values_returns_sanitized_data(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'cleaned', 'email' => 'a@b.com'],
            originals: ['name' => '<b>dirty</b>', 'email' => 'a@b.com'],
        );

        self::assertSame(['name' => 'cleaned', 'email' => 'a@b.com'], $result->values());
    }

    #[Test]
    public function original_returns_pre_sanitization_value(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'cleaned'],
            originals: ['name' => '<script>alert(1)</script>'],
        );

        self::assertSame('<script>alert(1)</script>', $result->original('name'));
    }

    #[Test]
    public function original_returns_null_for_unknown_field(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'cleaned'],
            originals: ['name' => 'dirty'],
        );

        self::assertNull($result->original('nonexistent'));
    }

    #[Test]
    public function was_modified_returns_true_when_values_changed(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'cleaned'],
            originals: ['name' => 'dirty'],
        );

        self::assertTrue($result->wasModified());
    }

    #[Test]
    public function was_modified_returns_false_when_no_changes(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'same', 'email' => 'a@b.com'],
            originals: ['name' => 'same', 'email' => 'a@b.com'],
        );

        self::assertFalse($result->wasModified());
    }

    #[Test]
    public function field_was_modified_detects_per_field_changes(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'cleaned', 'email' => 'a@b.com'],
            originals: ['name' => 'dirty', 'email' => 'a@b.com'],
        );

        self::assertTrue($result->fieldWasModified('name'));
        self::assertFalse($result->fieldWasModified('email'));
    }

    #[Test]
    public function field_was_modified_returns_false_for_unknown_field(): void
    {
        $result = new SanitizationResult(
            sanitized: ['name' => 'cleaned'],
            originals: ['name' => 'dirty'],
        );

        self::assertFalse($result->fieldWasModified('nonexistent'));
    }

    #[Test]
    public function handles_empty_data(): void
    {
        $result = new SanitizationResult(sanitized: [], originals: []);

        self::assertSame([], $result->values());
        self::assertFalse($result->wasModified());
    }
}
