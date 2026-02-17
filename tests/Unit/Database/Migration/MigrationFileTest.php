<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Migration\MigrationFile;
use ReflectionClass;

#[CoversClass(MigrationFile::class)]
final class MigrationFileTest extends TestCase
{
    #[Test]
    public function constructsWithAllProperties(): void
    {
        $file = new MigrationFile(
            version: '20240101_000001',
            name: 'create_users_table',
            path: '/app/migrations/20240101_000001_create_users_table.php',
        );

        self::assertSame('20240101_000001', $file->version);
        self::assertSame('create_users_table', $file->name);
        self::assertSame('/app/migrations/20240101_000001_create_users_table.php', $file->path);
    }

    #[Test]
    public function acceptsNumericVersionFormat(): void
    {
        $file = new MigrationFile(
            version: '001',
            name: 'initial',
            path: '/migrations/001_initial.php',
        );

        self::assertSame('001', $file->version);
    }

    #[Test]
    public function acceptsTimestampVersionFormat(): void
    {
        $file = new MigrationFile(
            version: '2024_03_15_143022',
            name: 'add_email_index',
            path: '/var/www/migrations/2024_03_15_143022_add_email_index.php',
        );

        self::assertSame('2024_03_15_143022', $file->version);
        self::assertSame('add_email_index', $file->name);
    }

    #[Test]
    public function isReadonly(): void
    {
        $file = new MigrationFile(version: 'v1', name: 'test', path: '/test.php');

        $reflection = new ReflectionClass($file);
        self::assertTrue($reflection->isReadOnly());
    }
}
