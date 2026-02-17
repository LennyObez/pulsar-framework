<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Migration\MigrationInterface;
use ReflectionClass;

/**
 * Validates that all new migration files from the table design audit
 * return valid MigrationInterface instances with both up() and down() methods.
 */
final class NewMigrationsValidationTest extends TestCase
{
    private const string BASE = __DIR__ . '/../../../../';

    /**
     * @return iterable<string, array{string}>
     */
    public static function migrationFileProvider(): iterable
    {
        $files = [
            // CMS migrations (042-045)
            'cms/042_fix_form_submissions_id_size' =>
                'extensions/cms/src/Migration/042_fix_form_submissions_id_size.php',
            'cms/043_add_soft_delete_columns' =>
                'extensions/cms/src/Migration/043_add_soft_delete_columns.php',
            'cms/044_add_version_columns' =>
                'extensions/cms/src/Migration/044_add_version_columns.php',
            'cms/045_add_data_classification_to_forms' =>
                'extensions/cms/src/Migration/045_add_data_classification_to_forms.php',

            // Forum migrations (019-021)
            'forum/019_add_missing_fk_indexes' =>
                'extensions/forum/src/Migration/019_add_missing_fk_indexes.php',
            'forum/020_add_soft_delete_to_tags' =>
                'extensions/forum/src/Migration/020_add_soft_delete_to_tags.php',
            'forum/021_add_tenant_id_to_tags' =>
                'extensions/forum/src/Migration/021_add_tenant_id_to_tags.php',

            // Analytics migrations
            'analytics/20260327000001_add_missing_fk_indexes' =>
                'extensions/analytics/src/Migration/20260327000001_add_missing_fk_indexes.php',
            'analytics/20260327000002_add_check_constraints' =>
                'extensions/analytics/src/Migration/20260327000002_add_check_constraints.php',

            // Health-status migration
            'health-status/20260327000003_add_check_constraints' =>
                'extensions/health-status/database/migrations/20260327000003_add_check_constraints.php',
        ];

        foreach ($files as $name => $path) {
            yield $name => [self::BASE . $path];
        }
    }

    #[Test]
    #[DataProvider('migrationFileProvider')]
    public function migrationFileReturnsMigrationInterface(string $path): void
    {
        self::assertFileExists($path);

        $migration = require $path;

        self::assertInstanceOf(
            MigrationInterface::class,
            $migration,
            "Migration file must return a MigrationInterface instance: {$path}",
        );
    }

    #[Test]
    #[DataProvider('migrationFileProvider')]
    public function migrationHasUpAndDownMethods(string $path): void
    {
        $migration = require $path;
        self::assertIsObject($migration);
        $reflection = new ReflectionClass($migration);

        self::assertTrue(
            $reflection->hasMethod('up'),
            "Migration must have an up() method: {$path}",
        );

        self::assertTrue(
            $reflection->hasMethod('down'),
            "Migration must have a down() method: {$path}",
        );

        $up = $reflection->getMethod('up');
        $down = $reflection->getMethod('down');

        self::assertTrue($up->isPublic(), "up() must be public: {$path}");
        self::assertTrue($down->isPublic(), "down() must be public: {$path}");
        self::assertSame(1, $up->getNumberOfParameters(), "up() must accept exactly one parameter: {$path}");
        self::assertSame(1, $down->getNumberOfParameters(), "down() must accept exactly one parameter: {$path}");
    }

    #[Test]
    #[DataProvider('migrationFileProvider')]
    public function migrationIsAnonymousClass(string $path): void
    {
        $migration = require $path;
        self::assertIsObject($migration);
        $reflection = new ReflectionClass($migration);

        self::assertTrue(
            $reflection->isAnonymous(),
            "Migration must be an anonymous class: {$path}",
        );
    }
}
