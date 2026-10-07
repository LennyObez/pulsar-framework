<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Internal\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Internal\Security\AdminSafetyMode;

#[CoversClass(AdminSafetyMode::class)]
final class AdminSafetyModeTest extends TestCase
{
    #[Test]
    public function productionStripsInternalKeys(): void
    {
        $mode = new AdminSafetyMode(debug: false);

        $data = [
            'message' => 'Something went wrong',
            'code' => 500,
            'trace' => 'at Foo.php:42',
            'sql' => 'SELECT * FROM users',
            'bindings' => [':p0' => 'admin'],
            'file' => '/app/src/Controller.php',
            'line' => 42,
            'class' => 'Controller',
            'function' => 'index',
        ];

        $sanitized = $mode->sanitize($data);

        self::assertArrayHasKey('message', $sanitized);
        self::assertArrayHasKey('code', $sanitized);
        self::assertArrayNotHasKey('trace', $sanitized);
        self::assertArrayNotHasKey('sql', $sanitized);
        self::assertArrayNotHasKey('bindings', $sanitized);
        self::assertArrayNotHasKey('file', $sanitized);
        self::assertArrayNotHasKey('line', $sanitized);
        self::assertArrayNotHasKey('class', $sanitized);
        self::assertArrayNotHasKey('function', $sanitized);
    }

    #[Test]
    public function developmentShowsAllKeys(): void
    {
        $mode = new AdminSafetyMode(debug: true);

        $data = [
            'message' => 'Error occurred',
            'trace' => 'at Foo.php:42',
            'sql' => 'SELECT 1',
        ];

        $sanitized = $mode->sanitize($data);

        self::assertSame($data, $sanitized);
    }

    #[Test]
    public function productionReplacesErrorMessage(): void
    {
        $mode = new AdminSafetyMode(debug: false);

        $message = $mode->errorMessage('SQL syntax error near SELECT');

        self::assertSame('An error occurred while processing your request', $message);
    }

    #[Test]
    public function developmentShowsInternalMessage(): void
    {
        $mode = new AdminSafetyMode(debug: true);

        $message = $mode->errorMessage('SQL syntax error near SELECT');

        self::assertSame('SQL syntax error near SELECT', $message);
    }

    #[Test]
    public function isDebugReflectsMode(): void
    {
        $debug = new AdminSafetyMode(debug: true);
        $prod = new AdminSafetyMode(debug: false);

        self::assertTrue($debug->isDebug());
        self::assertFalse($prod->isDebug());
    }

    #[Test]
    public function productionPreservesNonInternalKeys(): void
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
