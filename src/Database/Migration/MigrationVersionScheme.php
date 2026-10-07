<?php

declare(strict_types=1);

namespace Pulsar\Database\Migration;

use Pulsar\Api\Internal;

use function count;
use function hash;
use function implode;
use function preg_match;
use function sprintf;
use function substr;

/**
 * How a discovered migration file becomes the version string recorded in the
 * migrations table — and how a version recorded by an older Pulsar is recognised.
 *
 * ## The rule
 *
 * A timestamp filename (`20260203153000_create_users.php`) is globally unique and
 * is its own version. A sequential filename (`001_create_pages.php`) is unique only
 * inside the directory that ships it, so it is qualified by the SOURCE that ships
 * it: `crc32b` of the source label, four hex characters, an underscore, then the
 * zero-padded number.
 *
 * The label is a name — `project`, `core:Auth`, `ext:pulsar/cms` — and names travel.
 * Until 1.0.0-rc.12 the qualifier was `crc32b` of the migration directory's ABSOLUTE
 * PATH, which does not travel: the same migration was `a3f2_00000000000001` on a
 * developer machine and `7b1c_00000000000001` on the deploy host. Every already-applied
 * sequential migration therefore looked pending after a deploy into a different path,
 * and `pulsar migrate` re-ran it against the live database. That is why the qualifier
 * is derived from a name and from nothing else.
 *
 * ## Recognising the old identities
 *
 * The old and new qualifiers have the same SHAPE — four hex characters — because the
 * version column is `VARCHAR(30)` and a name is not guaranteed to fit. They are told
 * apart by their consequences rather than by their spelling: a version recorded in the
 * table that no longer exists on disk, whose zero-padded number matches a migration on
 * disk that has NOT been applied, is the same migration under its old identity.
 * {@see legacyAliases()} finds those pairs, and {@see describeMismatch()} writes the
 * `UPDATE` an operator runs to re-key the table.
 */
#[Internal(reason: 'Version derivation and upgrade reconciliation for the Migration module')]
final class MigrationVersionScheme
{
    /**
     * Versions that carry a source qualifier, or a bare zero-padded number.
     * Both are what {@see MigrationRepository::discover()} produces, and the
     * qualified form is also what every pre-1.0.0-rc.12 installation recorded.
     */
    private const string QUALIFIED_VERSION = '/^(?:([0-9a-f]{4})_)?(\d{14})$/';

    /**
     * The qualifier prefixed to a sequential version discovered under `$label`.
     *
     * An empty label yields an empty qualifier: an unlabelled directory has no
     * name to derive one from, and inventing one from its path is the defect this
     * class exists to prevent.
     */
    public static function prefixForSource(string $label): string
    {
        if ($label === '') {
            return '';
        }

        return substr(hash('crc32b', $label), 0, 4);
    }

    /**
     * The zero-padded number inside a qualified version, or null when the version
     * is not of a shape this scheme produces.
     */
    public static function numericTail(string $version): ?string
    {
        if (preg_match(self::QUALIFIED_VERSION, $version, $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    /**
     * Applied versions that name a migration this checkout now calls something else.
     *
     * An applied version qualifies when it is absent from disk AND some migration on
     * disk that has NOT been applied carries the same number. Exactly one such
     * migration makes the pair unambiguous and re-keyable; several make it ambiguous,
     * which is the ordinary case when two extensions each ship `001_` — and an
     * ambiguous pair is still a stop, because running would re-apply it.
     *
     * @param list<string> $applied Versions recorded in the migrations table
     * @param list<string> $discovered Versions {@see MigrationRepository::discover()} produced
     * @return array{mapped: array<string, string>, ambiguous: array<string, list<string>>}
     */
    public static function legacyAliases(array $applied, array $discovered): array
    {
        $appliedSet = [];
        foreach ($applied as $version) {
            $appliedSet[$version] = true;
        }

        $discoveredSet = [];
        $unappliedByTail = [];
        foreach ($discovered as $version) {
            $discoveredSet[$version] = true;

            if (isset($appliedSet[$version])) {
                continue;
            }

            $tail = self::numericTail($version);
            if ($tail !== null) {
                $unappliedByTail[$tail][] = $version;
            }
        }

        $mapped = [];
        $ambiguous = [];

        foreach ($applied as $version) {
            if (isset($discoveredSet[$version])) {
                continue;
            }

            $tail = self::numericTail($version);
            if ($tail === null) {
                continue;
            }

            $candidates = $unappliedByTail[$tail] ?? [];
            if ($candidates === []) {
                continue;
            }

            if (count($candidates) === 1) {
                $mapped[$version] = $candidates[0];

                continue;
            }

            $ambiguous[$version] = $candidates;
        }

        return ['mapped' => $mapped, 'ambiguous' => $ambiguous];
    }

    /**
     * Applied versions whose recorded name is not the name of the migration now on disk
     * at that version.
     *
     * The migrations table keys a migration by version and by nothing else — no column
     * says which source shipped the row. Two sources shipping one version therefore write
     * to the same row, and whichever ran second reads as already applied:
     * {@see MigrationRunner::getPending()} subtracts applied versions by key, drops it
     * from the run, and the schema it creates is never created. `migrate` prints nothing
     * pending and exits 0. That is the failure mode with no symptom until the first write
     * to the missing table, and it is why the tracking table is read for more than the
     * version alone.
     *
     * The `name` column is what makes it visible without a schema change: it holds the
     * description the applied file carried, so a row whose name is not the name of the
     * file now sitting at that version was written by a different migration.
     *
     * Only versions present on BOTH sides are compared. A recorded version with no file
     * is the uninstalled-extension case {@see legacyAliases()} already reasons about, and
     * a file with no record is simply pending.
     *
     * Keys are `array-key` rather than `string` because PHP turns a numeric version
     * string into an integer array key; both maps are built the same way, so lookups
     * stay consistent, and the caller stringifies for display.
     *
     * @param array<array-key, string> $applied Version => the name recorded in the table
     * @param array<array-key, string> $discovered Version => the name of the file on disk
     * @return array<array-key, array{recorded: string, onDisk: string}>
     */
    public static function nameDrift(array $applied, array $discovered): array
    {
        $drift = [];

        foreach ($applied as $version => $recorded) {
            $onDisk = $discovered[$version] ?? null;

            if ($onDisk === null || $onDisk === $recorded) {
                continue;
            }

            $drift[$version] = ['recorded' => $recorded, 'onDisk' => $onDisk];
        }

        return $drift;
    }

    /**
     * What an operator is told when a row and the file at its version are not the same
     * migration.
     *
     * No statement is offered to run, because there is no repair that is right in every
     * case and the wrong one destroys schema. Which of the two migrations the row records
     * decides everything, and only the operator can know: if the row belongs to a source
     * no longer installed, the migration on disk has never run and needs a version of its
     * own before it can be applied; if the file was renamed in place after being applied,
     * the row simply needs its name corrected. So this states the conflict, names both
     * sides, and stops.
     *
     * @param array<array-key, array{recorded: string, onDisk: string}> $drift
     */
    public static function describeNameDrift(string $table, array $drift): string
    {
        $lines = [
            sprintf(
                'The migrations table "%s" records %d version(s) under a different migration '
                . 'than the one now on disk at that version. Running would treat that migration '
                . 'as already applied and never run it.',
                $table,
                count($drift),
            ),
            '',
        ];

        foreach ($drift as $version => $names) {
            $lines[] = sprintf(
                '    %s  recorded as "%s", on disk as "%s"',
                (string) $version,
                $names['recorded'],
                $names['onDisk'],
            );
        }

        $lines[] = '';
        $lines[] = 'A version is the whole of a migration\'s identity here: the table has no column '
            . 'saying which source shipped a row. Two sources shipping one version write to the '
            . 'same row, and the second one\'s schema is then silently skipped.';
        $lines[] = '';
        $lines[] = 'Decide which migration the row records, in a maintenance window:';
        $lines[] = '  - if it came from a source that is no longer installed, give the migration on '
            . 'disk a version of its own and apply it;';
        $lines[] = '  - if the file was renamed after being applied, correct the row\'s name column '
            . 'to match, and do not rename applied migrations again.';

        return implode("\n", $lines);
    }

    /**
     * The instructions an operator needs to reconcile the table by hand.
     *
     * Deliberately an `UPDATE` the operator runs themselves rather than something
     * `pulsar migrate` performs: re-keying the migrations table rewrites the record
     * of what has been applied to a production database, and that belongs in a
     * maintenance window, in a reviewed statement, not in a side effect of a deploy.
     *
     * @param array<string, string> $mapped Applied version => the version this checkout produces
     * @param array<string, list<string>> $ambiguous Applied version => candidate versions
     */
    public static function describeMismatch(string $table, array $mapped, array $ambiguous): string
    {
        $lines = [
            sprintf(
                'The migrations table "%s" records %d migration(s) under version strings this '
                . 'checkout no longer produces, and the same migrations are on disk unapplied. '
                . 'Running now would re-apply them.',
                $table,
                count($mapped) + count($ambiguous),
            ),
            '',
            'Sequential migration versions used to be qualified by a CRC32 of the absolute '
            . 'migrations directory, so they changed whenever the checkout moved. They are now '
            . 'qualified by the name of the source that ships them and are the same on every host.',
            '',
        ];

        if ($mapped !== []) {
            $lines[] = 'Re-key these rows in a maintenance window, then re-run the migration:';
            $lines[] = '';

            foreach ($mapped as $old => $new) {
                $lines[] = sprintf(
                    "    UPDATE %s SET version = '%s' WHERE version = '%s';",
                    $table,
                    $new,
                    $old,
                );
            }

            $lines[] = '';
        }

        if ($ambiguous !== []) {
            $lines[] = 'These rows cannot be re-keyed automatically — several migrations on disk '
                . 'carry the same number, so which one each row recorded is not recoverable from '
                . 'the table alone. Match them against the source each came from:';
            $lines[] = '';

            foreach ($ambiguous as $old => $candidates) {
                $lines[] = sprintf('    %s  ->  one of: %s', $old, implode(', ', $candidates));
            }

            $lines[] = '';
        }

        $lines[] = 'Verify the updated row count against the number of statements before committing.';

        return implode("\n", $lines);
    }
}
