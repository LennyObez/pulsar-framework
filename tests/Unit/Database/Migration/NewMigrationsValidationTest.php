<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\DatabaseConfig;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\Migration\MigrationPathResolver;
use ReflectionClass;

use function array_merge;
use function basename;
use function glob;
use function realpath;
use function sort;
use function str_replace;
use function str_starts_with;

/**
 * Every migration file returns a usable {@see MigrationInterface}.
 *
 * ## Why the framework's own migrations are discovered rather than listed
 *
 * The extension entries below are a curated list, written once for the tables the design
 * audit covered. The framework's are not, and the reason is a defect this test failed to
 * catch: five migrations replacing the storages' boot-time `installSchema()` methods
 * (ADR-0043) landed under `src/<Module>/Database/Migration/`, and this test enumerated
 * extensions only — so the files that carried the framework's own schema were the ones
 * nothing here validated.
 *
 * A hardcoded list cannot fail to mention a file; it can only fail to be updated, which
 * is silent. Discovery cannot be silent: a migration added to any core module is validated
 * on the run that adds it, and a directory that moves empties the provider, which
 * {@see frameworkMigrationsAreDiscovered()} turns into a failure rather than a suite with
 * nothing in it.
 *
 * The directories come from {@see MigrationPathResolver} rather than from a glob written
 * out a second time here. `pulsar migrate` runs whatever that resolver returns, so asking
 * it is the difference between testing the migrations the framework ships and testing the
 * ones a copied pattern happens to still match.
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

        foreach (self::frameworkMigrations() as $path) {
            yield 'framework/' . basename($path, '.php') => [$path];
        }
    }

    /**
     * Every migration the framework itself ships, found rather than remembered.
     *
     * Every `.php` file in those directories counts, not only the ones whose names parse
     * as a version. {@see \Pulsar\Database\Migration\MigrationRepository::discover()}
     * silently skips a file it cannot parse, so filtering the same way here would hide a
     * misnamed migration behind a green suite instead of failing on it.
     *
     * @return list<string>
     */
    private static function frameworkMigrations(): array
    {
        $files = [];

        foreach (self::frameworkMigrationDirectories() as $directory) {
            $found = glob($directory . '/*.php');

            if ($found !== false) {
                $files = array_merge($files, $found);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The framework's own migration directories, as `pulsar migrate` resolves them.
     *
     * {@see MigrationPathResolver::resolve()} also returns the project's configured path
     * and, given a catalog, each enabled extension's — neither of which belongs here: the
     * extensions this test covers are the curated list above, and a project path is the
     * application's business. They are separated by location rather than by position in
     * the returned list, so a reordering of the resolver cannot quietly change what this
     * test validates.
     *
     * @return list<string>
     */
    private static function frameworkMigrationDirectories(): array
    {
        $src = realpath(self::BASE . 'src');

        if ($src === false) {
            return [];
        }

        $prefix = str_replace('\\', '/', $src) . '/';
        $resolver = new MigrationPathResolver(new DatabaseConfig(
            defaultConnection: 'sqlite',
            connections: [],
            migrationsTable: 'pulsar_migrations',
            migrationsPath: 'database/migrations',
        ));

        $directories = [];

        foreach ($resolver->resolve() as $path) {
            if (str_starts_with(str_replace('\\', '/', $path), $prefix)) {
                $directories[] = $path;
            }
        }

        return $directories;
    }

    /**
     * The resolver must find something.
     *
     * An empty provider is a passing suite that tests nothing, and PHPUnit's
     * `failOnEmptyTestSuite` cannot see the difference because the extension entries keep
     * the suite non-empty. A renamed directory would therefore silently stop validating
     * every migration the framework ships — which is the exact shape of the gap this test
     * did not catch the first time.
     */
    #[Test]
    public function frameworkMigrationsAreDiscovered(): void
    {
        self::assertNotSame(
            [],
            self::frameworkMigrations(),
            'MigrationPathResolver returned no framework migration directory holding a file — '
            . 'the framework ships migrations, so either the resolver or the tree is wrong',
        );
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
