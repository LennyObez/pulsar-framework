<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Migration\MigrationVersionScheme;

#[CoversClass(MigrationVersionScheme::class)]
final class MigrationVersionSchemeTest extends TestCase
{
    /**
     * The qualifier depends on the source name and on nothing else.
     *
     * This is the property the whole scheme rests on: the same name yields the same
     * four characters on a laptop, in CI and on a deploy host, so a migration keeps one
     * identity for the life of the database it was applied to.
     */
    #[Test]
    public function theQualifierIsAPureFunctionOfTheSourceName(): void
    {
        self::assertSame(
            MigrationVersionScheme::prefixForSource('ext:pulsar/cms'),
            MigrationVersionScheme::prefixForSource('ext:pulsar/cms'),
        );

        self::assertNotSame(
            MigrationVersionScheme::prefixForSource('ext:pulsar/cms'),
            MigrationVersionScheme::prefixForSource('ext:pulsar/forum'),
        );

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{4}$/',
            MigrationVersionScheme::prefixForSource('ext:pulsar/cms'),
        );
    }

    #[Test]
    public function anUnnamedSourceGetsNoQualifier(): void
    {
        self::assertSame('', MigrationVersionScheme::prefixForSource(''));
    }

    #[Test]
    public function theNumericTailIsReadFromBothQualifiedAndBareVersions(): void
    {
        self::assertSame('00000000000001', MigrationVersionScheme::numericTail('a3f2_00000000000001'));
        self::assertSame('00000000000001', MigrationVersionScheme::numericTail('00000000000001'));
        self::assertSame('20240101120000', MigrationVersionScheme::numericTail('20240101120000'));
    }

    #[Test]
    public function aVersionOfAnotherShapeHasNoTail(): void
    {
        self::assertNull(MigrationVersionScheme::numericTail('not-a-version'));
        self::assertNull(MigrationVersionScheme::numericTail('zzzz_00000000000001'));
        self::assertNull(MigrationVersionScheme::numericTail('a3f2_001'));
        self::assertNull(MigrationVersionScheme::numericTail(''));
    }

    /**
     * One applied version, absent from disk, whose number matches exactly one
     * unapplied migration: the same migration under the identity an older Pulsar
     * derived from the checkout path.
     */
    #[Test]
    public function aRenamedIdentityIsPairedWithTheMigrationItNames(): void
    {
        $aliases = MigrationVersionScheme::legacyAliases(
            ['a3f2_00000000000001'],
            ['9b2c_00000000000001'],
        );

        self::assertSame(['a3f2_00000000000001' => '9b2c_00000000000001'], $aliases['mapped']);
        self::assertSame([], $aliases['ambiguous']);
    }

    /**
     * Two sources shipping `001_` is the ordinary case, and it makes the pairing
     * unrecoverable from the table alone. Reported as ambiguous rather than guessed:
     * a wrong guess re-runs a migration on a live database.
     */
    #[Test]
    public function severalCandidatesAreReportedRatherThanGuessed(): void
    {
        $aliases = MigrationVersionScheme::legacyAliases(
            ['a3f2_00000000000001'],
            ['9b2c_00000000000001', '77aa_00000000000001'],
        );

        self::assertSame([], $aliases['mapped']);
        self::assertSame(
            ['a3f2_00000000000001' => ['9b2c_00000000000001', '77aa_00000000000001']],
            $aliases['ambiguous'],
        );
    }

    /**
     * A migration whose file was deleted after it was applied has no twin waiting to
     * be re-run, so it is not an identity mismatch and must not stop a deploy.
     */
    #[Test]
    public function anAppliedVersionWithNoUnappliedTwinIsNotAnAlias(): void
    {
        $aliases = MigrationVersionScheme::legacyAliases(
            ['a3f2_00000000000001'],
            ['9b2c_00000000000002'],
        );

        self::assertSame([], $aliases['mapped']);
        self::assertSame([], $aliases['ambiguous']);
    }

    /**
     * A candidate that is itself already applied is not a twin: both rows exist, so
     * nothing would be re-run.
     */
    #[Test]
    public function anAlreadyAppliedCandidateIsNotATwin(): void
    {
        $aliases = MigrationVersionScheme::legacyAliases(
            ['a3f2_00000000000001', '9b2c_00000000000001'],
            ['9b2c_00000000000001'],
        );

        self::assertSame([], $aliases['mapped']);
        self::assertSame([], $aliases['ambiguous']);
    }

    #[Test]
    public function aVersionStillPresentOnDiskIsNeverAnAlias(): void
    {
        $aliases = MigrationVersionScheme::legacyAliases(
            ['20240101120000'],
            ['20240101120000', '20240202120000'],
        );

        self::assertSame([], $aliases['mapped']);
        self::assertSame([], $aliases['ambiguous']);
    }

    /**
     * The message is the whole remedy an operator gets, so it has to carry the exact
     * statement rather than a description of one.
     */
    #[Test]
    public function theMismatchDescriptionCarriesTheStatementToRun(): void
    {
        $message = MigrationVersionScheme::describeMismatch(
            'pulsar_migrations',
            ['a3f2_00000000000001' => '9b2c_00000000000001'],
            [],
        );

        self::assertStringContainsString(
            "UPDATE pulsar_migrations SET version = '9b2c_00000000000001' "
            . "WHERE version = 'a3f2_00000000000001';",
            $message,
        );
        self::assertStringContainsString('maintenance window', $message);
        self::assertStringContainsString('records 1 migration(s)', $message);
    }

    #[Test]
    public function theMismatchDescriptionListsCandidatesItCannotChooseBetween(): void
    {
        $message = MigrationVersionScheme::describeMismatch(
            'pulsar_migrations',
            [],
            ['a3f2_00000000000001' => ['9b2c_00000000000001', '77aa_00000000000001']],
        );

        self::assertStringContainsString('cannot be re-keyed automatically', $message);
        self::assertStringContainsString(
            'a3f2_00000000000001  ->  one of: 9b2c_00000000000001, 77aa_00000000000001',
            $message,
        );
        self::assertStringNotContainsString('UPDATE', $message);
    }

    /**
     * A version present on both sides under two different names is the collision.
     */
    #[Test]
    public function aRowNamingADifferentMigrationThanTheFileAtItsVersionIsDrift(): void
    {
        $drift = MigrationVersionScheme::nameDrift(
            ['20260327000001' => 'add_missing_fk_indexes'],
            ['20260327000001' => 'create_health_check_history'],
        );

        self::assertSame(
            ['20260327000001' => [
                'recorded' => 'add_missing_fk_indexes',
                'onDisk' => 'create_health_check_history',
            ]],
            $drift,
        );
    }

    /**
     * The two ordinary shapes are not drift, and saying so is the whole difference
     * between a guard and a wall: a row whose file is gone belongs to an uninstalled
     * source, and a file with no row is simply pending.
     */
    #[Test]
    public function aVersionOnOnlyOneSideIsNotDrift(): void
    {
        self::assertSame(
            [],
            MigrationVersionScheme::nameDrift(
                ['20260327000001' => 'removed_long_ago'],
                ['20260716000001' => 'create_analytics_visitor_salts'],
            ),
        );
    }

    #[Test]
    public function aRowNamingTheMigrationOnDiskIsNotDrift(): void
    {
        self::assertSame(
            [],
            MigrationVersionScheme::nameDrift(
                ['20260327000001' => 'create_2fa_tables'],
                ['20260327000001' => 'create_2fa_tables'],
            ),
        );
    }

    /**
     * The description names both sides and offers no statement to run: which of the two
     * migrations the row records decides the repair, and only the operator knows.
     */
    #[Test]
    public function theDriftDescriptionNamesBothMigrationsAndPrescribesNoStatement(): void
    {
        $message = MigrationVersionScheme::describeNameDrift('pulsar_migrations', [
            '20260327000001' => [
                'recorded' => 'add_missing_fk_indexes',
                'onDisk' => 'create_health_check_history',
            ],
        ]);

        self::assertStringContainsString('records 1 version(s)', $message);
        self::assertStringContainsString(
            '20260327000001  recorded as "add_missing_fk_indexes", on disk as "create_health_check_history"',
            $message,
        );
        self::assertStringContainsString('maintenance window', $message);
        self::assertStringNotContainsString('UPDATE', $message);
    }
}
