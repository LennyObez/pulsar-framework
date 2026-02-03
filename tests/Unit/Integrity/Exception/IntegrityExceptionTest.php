<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Integrity\Exception\IntegrityException;
use RuntimeException;

#[CoversClass(IntegrityException::class)]
final class IntegrityExceptionTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $exception = IntegrityException::manifestNotFound('/path/to/manifest.json');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function manifest_not_found_contains_path(): void
    {
        $exception = IntegrityException::manifestNotFound('/var/integrity/manifest.json');

        self::assertStringContainsString('/var/integrity/manifest.json', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function manifest_corrupted_contains_path_and_reason(): void
    {
        $exception = IntegrityException::manifestCorrupted('/manifest.json', 'invalid JSON');

        self::assertStringContainsString('/manifest.json', $exception->getMessage());
        self::assertStringContainsString('corrupted', $exception->getMessage());
        self::assertStringContainsString('invalid JSON', $exception->getMessage());
    }

    #[Test]
    public function signature_invalid_returns_descriptive_message(): void
    {
        $exception = IntegrityException::signatureInvalid();

        self::assertStringContainsString('signature', $exception->getMessage());
        self::assertStringContainsString('invalid', $exception->getMessage());
    }

    #[Test]
    public function verification_failed_contains_counts(): void
    {
        $exception = IntegrityException::verificationFailed(3, 2);

        self::assertStringContainsString('3 modified', $exception->getMessage());
        self::assertStringContainsString('2 missing', $exception->getMessage());
    }

    #[Test]
    public function verification_failed_with_zero_counts(): void
    {
        $exception = IntegrityException::verificationFailed(0, 0);

        self::assertStringContainsString('0 modified', $exception->getMessage());
        self::assertStringContainsString('0 missing', $exception->getMessage());
    }

    #[Test]
    public function build_failed_contains_reason(): void
    {
        $exception = IntegrityException::buildFailed('failed to compute hash for "src/Kernel.php"');

        self::assertStringContainsString('Failed to build', $exception->getMessage());
        self::assertStringContainsString('failed to compute hash for "src/Kernel.php"', $exception->getMessage());
    }

    #[Test]
    public function all_factories_return_integrity_exception_instance(): void
    {
        self::assertInstanceOf(IntegrityException::class, IntegrityException::manifestNotFound('path'));
        self::assertInstanceOf(IntegrityException::class, IntegrityException::manifestCorrupted('path', 'reason'));
        self::assertInstanceOf(IntegrityException::class, IntegrityException::signatureInvalid());
        self::assertInstanceOf(IntegrityException::class, IntegrityException::verificationFailed(1, 1));
        self::assertInstanceOf(IntegrityException::class, IntegrityException::buildFailed('reason'));
    }
}
