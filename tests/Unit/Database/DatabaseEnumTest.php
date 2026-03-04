<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\DriverVariant;
use Pulsar\Database\FetchMode;
use Pulsar\Database\Migration\MigrationDirection;
use Pulsar\Database\Routing\ConnectionRole;

#[CoversClass(ConnectionRole::class)]
#[CoversClass(MigrationDirection::class)]
#[CoversClass(DriverVariant::class)]
#[CoversClass(FetchMode::class)]
final class DatabaseEnumTest extends TestCase
{
    // ── ConnectionRole ──────────────────────────────────────────────────

    #[Test]
    public function connectionRoleHasTwoCases(): void
    {
        self::assertCount(2, ConnectionRole::cases());
    }

    #[Test]
    #[DataProvider('connectionRoleProvider')]
    public function connectionRoleBackedValues(ConnectionRole $role, string $expected): void
    {
        self::assertSame($expected, $role->value);
    }

    /**
     * @return iterable<string, array{ConnectionRole, string}>
     */
    public static function connectionRoleProvider(): iterable
    {
        yield 'Read' => [ConnectionRole::Read, 'read'];
        yield 'Write' => [ConnectionRole::Write, 'write'];
    }

    #[Test]
    public function connectionRoleFromBackedValue(): void
    {
        self::assertSame(ConnectionRole::Read, ConnectionRole::from('read'));
        self::assertSame(ConnectionRole::Write, ConnectionRole::from('write'));
    }

    // ── MigrationDirection ──────────────────────────────────────────────

    #[Test]
    public function migrationDirectionHasTwoCases(): void
    {
        self::assertCount(2, MigrationDirection::cases());
    }

    #[Test]
    #[DataProvider('migrationDirectionProvider')]
    public function migrationDirectionBackedValues(MigrationDirection $dir, string $expected): void
    {
        self::assertSame($expected, $dir->value);
    }

    /**
     * @return iterable<string, array{MigrationDirection, string}>
     */
    public static function migrationDirectionProvider(): iterable
    {
        yield 'Up' => [MigrationDirection::Up, 'up'];
        yield 'Down' => [MigrationDirection::Down, 'down'];
    }

    // ── DriverVariant ───────────────────────────────────────────────────

    #[Test]
    public function driverVariantHasThreeCases(): void
    {
        self::assertCount(3, DriverVariant::cases());
    }

    #[Test]
    #[DataProvider('driverVariantProvider')]
    public function driverVariantBackedValues(DriverVariant $variant, string $expected): void
    {
        self::assertSame($expected, $variant->value);
    }

    /**
     * @return iterable<string, array{DriverVariant, string}>
     */
    public static function driverVariantProvider(): iterable
    {
        yield 'Standard' => [DriverVariant::Standard, 'standard'];
        yield 'MariaDb' => [DriverVariant::MariaDb, 'mariadb'];
        yield 'PerconaServer' => [DriverVariant::PerconaServer, 'percona'];
    }

    #[Test]
    #[DataProvider('driverVariantDetectionProvider')]
    public function driverVariantDetectsFromVersionString(string $versionString, DriverVariant $expected): void
    {
        self::assertSame($expected, DriverVariant::detect($versionString));
    }

    /**
     * @return iterable<string, array{string, DriverVariant}>
     */
    public static function driverVariantDetectionProvider(): iterable
    {
        yield 'MariaDB version string' => ['10.5.18-MariaDB', DriverVariant::MariaDb];
        yield 'MariaDB lowercase' => ['10.11.2-mariadb', DriverVariant::MariaDb];
        yield 'Percona Server' => ['8.0.35-26-Percona Server', DriverVariant::PerconaServer];
        yield 'Percona lowercase' => ['8.0.35-percona', DriverVariant::PerconaServer];
        yield 'Standard MySQL' => ['8.0.35', DriverVariant::Standard];
        yield 'Standard with suffix' => ['8.0.35-ubuntu', DriverVariant::Standard];
    }

    // ── FetchMode ───────────────────────────────────────────────────────

    #[Test]
    public function fetchModeHasFourCases(): void
    {
        self::assertCount(4, FetchMode::cases());
    }

    #[Test]
    #[DataProvider('fetchModeProvider')]
    public function fetchModeBackedValues(FetchMode $mode, int $expected): void
    {
        self::assertSame($expected, $mode->value);
    }

    /**
     * @return iterable<string, array{FetchMode, int}>
     */
    public static function fetchModeProvider(): iterable
    {
        yield 'Associative' => [FetchMode::Associative, PDO::FETCH_ASSOC];
        yield 'Numeric' => [FetchMode::Numeric, PDO::FETCH_NUM];
        yield 'Both' => [FetchMode::Both, PDO::FETCH_BOTH];
        yield 'Column' => [FetchMode::Column, PDO::FETCH_COLUMN];
    }
}
