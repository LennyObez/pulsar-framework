<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Queue\Serialization;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Queue\Serialization\SchemaVersionRegistry;

#[CoversClass(SchemaVersionRegistry::class)]
final class SchemaVersionRegistryTest extends TestCase
{
    #[Test]
    public function currentVersionReturnsOneForUnregisteredClass(): void
    {
        $registry = new SchemaVersionRegistry();

        self::assertSame(1, $registry->currentVersion('App\\Jobs\\Unknown'));
    }

    #[Test]
    public function registerUpdatesCurrentVersion(): void
    {
        $registry = new SchemaVersionRegistry();
        $registry->register('App\\Jobs\\SendEmail', 3);

        self::assertSame(3, $registry->currentVersion('App\\Jobs\\SendEmail'));
    }

    #[Test]
    #[DataProvider('validationProvider')]
    public function validateChecksVersionBounds(int $currentVersion, int $payloadVersion, bool $expected): void
    {
        $registry = new SchemaVersionRegistry();
        $registry->register('App\\Jobs\\Test', $currentVersion);

        self::assertSame($expected, $registry->validate('App\\Jobs\\Test', $payloadVersion));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function validationProvider(): iterable
    {
        yield 'same version' => [3, 3, true];
        yield 'older valid version' => [3, 1, true];
        yield 'future version rejected' => [3, 4, false];
        yield 'zero version rejected' => [3, 0, false];
        yield 'negative version rejected' => [3, -1, false];
        yield 'version 1 is always valid' => [3, 1, true];
    }

    #[Test]
    public function validateWorksForUnregisteredClass(): void
    {
        $registry = new SchemaVersionRegistry();

        self::assertTrue($registry->validate('App\\Jobs\\New', 1));
        self::assertFalse($registry->validate('App\\Jobs\\New', 2));
    }
}
