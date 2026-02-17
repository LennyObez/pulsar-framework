<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Support\Toast;

#[CoversClass(Toast::class)]
final class ToastTest extends TestCase
{
    #[Test]
    public function success_creates_toast_with_5s_default_duration(): void
    {
        $toast = Toast::success('Saved successfully');

        self::assertSame('Saved successfully', $toast->message);
        self::assertSame('success', $toast->type);
        self::assertSame(5000, $toast->duration);
    }

    #[Test]
    public function error_creates_toast_with_8s_default_duration(): void
    {
        $toast = Toast::error('Something failed');

        self::assertSame('Something failed', $toast->message);
        self::assertSame('error', $toast->type);
        self::assertSame(8000, $toast->duration);
    }

    #[Test]
    public function info_creates_toast_with_5s_default_duration(): void
    {
        $toast = Toast::info('For your information');

        self::assertSame('info', $toast->type);
        self::assertSame(5000, $toast->duration);
    }

    #[Test]
    public function warning_creates_toast_with_6s_default_duration(): void
    {
        $toast = Toast::warning('Be careful');

        self::assertSame('warning', $toast->type);
        self::assertSame(6000, $toast->duration);
    }

    #[Test]
    public function success_with_custom_duration(): void
    {
        $toast = Toast::success('Done', 3000);

        self::assertSame(3000, $toast->duration);
    }

    #[Test]
    public function withMessage_returns_new_toast_with_different_message(): void
    {
        $original = Toast::success('Original');
        $modified = $original->withMessage('Modified');

        self::assertSame('Modified', $modified->message);
        self::assertSame('success', $modified->type);
        self::assertSame(5000, $modified->duration);
        self::assertSame('Original', $original->message);
    }

    #[Test]
    public function jsonSerialize_returns_expected_structure(): void
    {
        $toast = Toast::error('Error occurred', 10000);
        $serialized = $toast->jsonSerialize();

        self::assertSame([
            'message' => 'Error occurred',
            'type' => 'error',
            'duration' => 10000,
        ], $serialized);
    }

    #[Test]
    public function json_encode_produces_valid_json(): void
    {
        $toast = Toast::info('Test message');
        $json = json_encode($toast);

        self::assertIsString($json);
        $decoded = json_decode($json, true);
        self::assertSame('Test message', $decoded['message']);
        self::assertSame('info', $decoded['type']);
        self::assertSame(5000, $decoded['duration']);
    }
}
