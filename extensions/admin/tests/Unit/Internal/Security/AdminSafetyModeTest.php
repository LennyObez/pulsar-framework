<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Security\AdminSafetyMode;

final class AdminSafetyModeTest extends TestCase
{
    #[Test]
    public function debug_mode_returns_full_error_data(): void
    {
        $mode = new AdminSafetyMode(debug: true);

        $errorData = [
            'message' => 'Something went wrong',
            'trace' => 'stack trace here',
            'sql' => 'SELECT * FROM users',
            'bindings' => [1, 2],
            'file' => '/app/src/Foo.php',
            'line' => 42,
            'class' => 'Foo',
            'function' => 'bar',
        ];

        $sanitized = $mode->sanitize($errorData);

        self::assertSame($errorData, $sanitized);
    }

    #[Test]
    public function production_mode_strips_internal_keys(): void
    {
        $mode = new AdminSafetyMode(debug: false);

        $errorData = [
            'message' => 'Something went wrong',
            'trace' => 'stack trace here',
            'sql' => 'SELECT * FROM users',
            'bindings' => [1, 2],
            'file' => '/app/src/Foo.php',
            'line' => 42,
            'class' => 'Foo',
            'function' => 'bar',
        ];

        $sanitized = $mode->sanitize($errorData);

        self::assertArrayHasKey('message', $sanitized);
        self::assertArrayNotHasKey('trace', $sanitized);
        self::assertArrayNotHasKey('sql', $sanitized);
        self::assertArrayNotHasKey('bindings', $sanitized);
        self::assertArrayNotHasKey('file', $sanitized);
        self::assertArrayNotHasKey('line', $sanitized);
        self::assertArrayNotHasKey('class', $sanitized);
        self::assertArrayNotHasKey('function', $sanitized);
    }

    #[Test]
    public function debug_error_message_returns_original(): void
    {
        $mode = new AdminSafetyMode(debug: true);

        self::assertSame(
            'Connection refused on port 5432',
            $mode->errorMessage('Connection refused on port 5432'),
        );
    }

    #[Test]
    public function production_error_message_returns_generic(): void
    {
        $mode = new AdminSafetyMode(debug: false);

        self::assertSame(
            'An error occurred while processing your request',
            $mode->errorMessage('Connection refused on port 5432'),
        );
    }

    #[Test]
    public function is_debug_returns_debug_state(): void
    {
        self::assertTrue(new AdminSafetyMode(debug: true)->isDebug());
        self::assertFalse(new AdminSafetyMode(debug: false)->isDebug());
    }

    #[Test]
    public function production_preserves_non_internal_keys(): void
    {
        $mode = new AdminSafetyMode(debug: false);

        $sanitized = $mode->sanitize([
            'message' => 'Error',
            'code' => 500,
            'trace' => 'hidden',
        ]);

        self::assertSame('Error', $sanitized['message']);
        self::assertSame(500, $sanitized['code']);
        self::assertArrayNotHasKey('trace', $sanitized);
    }
}
