<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Support\Toast;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function strlen;

#[CoversClass(Toast::class)]
#[CoversClass(UuidGenerator::class)]
final class ToastAndUuidTest extends TestCase
{
    // --- Toast ---

    #[Test]
    public function toastSuccess(): void
    {
        $toast = Toast::success('Saved');

        self::assertSame('Saved', $toast->message);
        self::assertSame('success', $toast->type);
        self::assertSame(5000, $toast->duration);
    }

    #[Test]
    public function toastError(): void
    {
        $toast = Toast::error('Something failed');

        self::assertSame('error', $toast->type);
        self::assertSame(8000, $toast->duration);
    }

    #[Test]
    public function toastInfo(): void
    {
        $toast = Toast::info('FYI');

        self::assertSame('info', $toast->type);
        self::assertSame(5000, $toast->duration);
    }

    #[Test]
    public function toastWarning(): void
    {
        $toast = Toast::warning('Careful');

        self::assertSame('warning', $toast->type);
        self::assertSame(6000, $toast->duration);
    }

    #[Test]
    public function toastCustomDuration(): void
    {
        $toast = Toast::success('Quick', duration: 2000);

        self::assertSame(2000, $toast->duration);
    }

    #[Test]
    public function toastJsonSerialize(): void
    {
        $toast = Toast::error('Oops', duration: 3000);
        $json = $toast->jsonSerialize();

        self::assertSame([
            'message' => 'Oops',
            'type' => 'error',
            'duration' => 3000,
        ], $json);
    }

    #[Test]
    public function toastJsonEncodeProducesValidJson(): void
    {
        $toast = Toast::info('Test');
        $encoded = json_encode($toast, JSON_THROW_ON_ERROR);

        self::assertJson($encoded);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Test', $decoded['message']);
        self::assertSame('info', $decoded['type']);
    }

    // --- UuidGenerator ---

    #[Test]
    public function v7ProducesValidFormat(): void
    {
        $uuid = UuidGenerator::v7();

        // Format: 8-4-4-4-9 (last segment is 9 hex chars due to 8-byte random)
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{9}$/',
            $uuid,
        );
    }

    #[Test]
    public function v7IsVersion7(): void
    {
        $uuid = UuidGenerator::v7();
        $parts = explode('-', $uuid);

        // Version nibble is the first character of the 3rd segment
        self::assertSame('7', $parts[2][0]);
    }

    #[Test]
    public function v7HasCorrectVariant(): void
    {
        $uuid = UuidGenerator::v7();
        $parts = explode('-', $uuid);

        // Variant bits: first nibble of 4th segment must be 8, 9, a, or b
        $variantChar = $parts[3][0];
        self::assertContains($variantChar, ['8', '9', 'a', 'b']);
    }

    #[Test]
    public function v7ProducesUniqueValues(): void
    {
        $uuids = [];

        for ($i = 0; $i < 100; $i++) {
            $uuids[] = UuidGenerator::v7();
        }

        self::assertCount(100, array_unique($uuids));
    }

    #[Test]
    public function v7IsMonotonicallyIncreasing(): void
    {
        $first = UuidGenerator::v7();
        usleep(1000); // 1ms
        $second = UuidGenerator::v7();

        // First 12 hex chars encode millisecond timestamp; later should be >= earlier
        $firstTime = substr(str_replace('-', '', $first), 0, 12);
        $secondTime = substr(str_replace('-', '', $second), 0, 12);

        self::assertGreaterThanOrEqual($firstTime, $secondTime);
    }

    #[Test]
    public function v7HasExpectedLength(): void
    {
        $uuid = UuidGenerator::v7();

        // 8+1+4+1+4+1+4+1+9 = 33 chars (last segment is 9 hex from 8 random bytes)
        self::assertSame(33, strlen($uuid));
    }
}
