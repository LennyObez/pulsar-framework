<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\FeatureFlag\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\FeatureFlag\Exception\FeatureFlagException;
use RuntimeException;

#[CoversClass(FeatureFlagException::class)]
final class FeatureFlagExceptionTest extends TestCase
{
    #[Test]
    public function storageErrorContainsReason(): void
    {
        $e = FeatureFlagException::storageError('Cannot read file: /tmp/flags.json');

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertStringContainsString('Cannot read file', $e->getMessage());
        self::assertStringContainsString('storage error', $e->getMessage());
    }

    #[Test]
    public function invalidDefinitionContainsFlagNameAndReason(): void
    {
        $e = FeatureFlagException::invalidDefinition('my-flag', 'percentage must be 0-100');

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertStringContainsString('my-flag', $e->getMessage());
        self::assertStringContainsString('percentage must be 0-100', $e->getMessage());
    }

    #[Test]
    public function storageErrorIsThrowable(): void
    {
        $this->expectException(FeatureFlagException::class);

        throw FeatureFlagException::storageError('disk full');
    }

    #[Test]
    public function invalidDefinitionIsThrowable(): void
    {
        $this->expectException(FeatureFlagException::class);

        throw FeatureFlagException::invalidDefinition('broken-flag', 'invalid type');
    }
}
