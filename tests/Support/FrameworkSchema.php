<?php

declare(strict_types=1);

namespace Pulsar\Tests\Support;

use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Migration\MigrationInterface;
use RuntimeException;

use function dirname;
use function is_file;
use function sprintf;

/**
 * Give a test the tables the framework's own migrations create — by running those files.
 *
 * The storages used to carry an `installSchema()` that emitted the same DDL a second way,
 * and every test that needed a table called it. That is what made the two definitions
 * drift undetected: the only path anybody exercised was the one no deployment used, and
 * the migration nobody ran was the one that had to be right. The installers are gone
 * (ADR-0043), so a test that wants `saga_states` has exactly one way to get it, and it is
 * the way a deploy gets it.
 *
 * The alternative — re-declaring the DDL inline in each test, with the table renamed so
 * runs cannot collide — is worse than doing nothing, and
 * {@see \Pulsar\Tests\Contract\TotpReplayGuardMigrationContractTest} carries the receipt:
 * a suite built that way passed on all three engines against a migration that threw on
 * MySQL, because the copy had quietly omitted the one method that failed. A test that
 * reproduces the code under test cannot disagree with it. It can only disagree with the
 * copy.
 *
 * So this requires the real file, on the real table names, and returns the real
 * {@see MigrationInterface}. Callers that want a table run {@see up()}; callers that are
 * testing the migration itself take the object from {@see load()} and drive it.
 */
final class FrameworkSchema
{
    public const string SAGA_STATES =
        'src/Workflow/Database/Migration/20260821000001_create_saga_states_table.php';

    public const string WORKFLOW_TABLES =
        'src/Workflow/Database/Migration/20260821000002_create_workflow_tables.php';

    public const string FAILED_JOBS =
        'src/Queue/Database/Migration/20260821000003_create_failed_jobs_table.php';

    public const string OUTBOX_EVENTS =
        'src/Event/Database/Migration/20260821000004_create_event_outbox_table.php';

    public const string SAGA_STEP_RESULTS =
        'src/Workflow/Database/Migration/20260821000005_create_saga_step_results_table.php';

    /**
     * Run one or more migrations against the connection, in the order given.
     *
     * Order is the caller's to choose because it is the caller who knows what depends on
     * what; nothing here reorders the list, so a test that gets it wrong fails on the
     * dependency rather than being silently rescued.
     */
    public static function up(ConnectionInterface $connection, string ...$migrations): void
    {
        foreach ($migrations as $migration) {
            self::load($migration)->up($connection);
        }
    }

    /**
     * The migration object the file returns.
     *
     * Freshly required each time rather than cached. Every migration in this repository
     * is an anonymous class holding no state between runs, but a cache would make that an
     * assumption the caller cannot see, and a test driving `up()` then `down()` then
     * `up()` is exactly where a stale instance would be hard to spot.
     *
     * @throws RuntimeException When the path names no file, which means a migration has
     *                          been renamed and this constant was not.
     */
    public static function load(string $migration): MigrationInterface
    {
        $path = dirname(__DIR__, 2) . '/' . $migration;

        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'No migration at %s. FrameworkSchema names migration files by path, so a '
                . 'renamed or deleted migration has to be renamed here too.',
                $path,
            ));
        }

        /** @var MigrationInterface */
        return require $path;
    }
}
