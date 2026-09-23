<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\BackupRoundTripObserver;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupManifest;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupVerification;
use Pulsar\Resilience\Backup\RestoreReport;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Security\Crypto\MasterKey;
use Random\Engine\Secure;
use Random\Randomizer;

use function bin2hex;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The recovery fact is a round trip, not a resolved binding.
 *
 * WHAT THIS FILE DEFENDS. NIST CSF RC.RP, SOC 2 A1.3 and HIPAA §164.308(a)(7) are
 * carried by one fact, and the fact is produced here. The observer shipped with
 * no test at all, which meant the only evidence that it distinguishes a working
 * deployment from a broken one was its own docblock — the exact shape ADR-0041
 * recorded and ADR-0061 removed, one level up.
 *
 * THE BROKEN SERVICES BELOW ARE THE POINT. Each one binds, constructs, and
 * answers `backup_primitive_resolved` identically to the shipped driver, and each
 * fails a different one of the three properties the controls actually name: the
 * copy is confidential, it is tamper-evident, and it gives back what it was
 * given. A deployment running any of them must not report a recovery capability.
 */
#[CoversClass(BackupRoundTripObserver::class)]
#[CoversClass(RecoveryCapabilityProbe::class)]
final class BackupRoundTripObserverTest extends TestCase
{
    /** @var non-empty-string */
    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pulsar-roundtrip-', true);

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o750, true);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        self::removeTree($this->directory);
    }

    // --- The deployment this framework ships -----------------------------------

    #[Test]
    public function theShippedDriverSealsVerifiesRefusesAndRestores(): void
    {
        $observation = self::observer()->observe(self::shippedService(), $this->destination());

        self::assertSame(ObservationId::BackupRoundTripVerified, $observation->id);
        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertTrue(
            $observation->isAdmissibleAsProof(),
            'a real backup, sealed, refused when altered and restored is the only kind of fact '
                . 'that may carry a recovery control',
        );
        self::assertStringContainsString('restored byte for byte', $observation->detail);
    }

    #[Test]
    public function theProbeLeavesNothingBehindInTheBackupDestination(): void
    {
        (void) self::observer()->observe(self::shippedService(), $this->destination());

        // An archive this check left behind would sit in the operator's backup
        // directory looking exactly like a real one, and would be picked up by a
        // recovery. Both files it writes have to go.
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . BackupRoundTripObserver::PROBE_ARCHIVE,
        );
        self::assertFileDoesNotExist(
            $this->directory . DIRECTORY_SEPARATOR . BackupRoundTripObserver::PROBE_ARCHIVE . '.altered',
        );
        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*'));
    }

    #[Test]
    public function aLeftoverProbeArtefactDoesNotWedgeEveryLaterRun(): void
    {
        // The one file in the destination this check owns outright, left behind by
        // a run whose removal failed -- a read-only volume, a crash between the
        // write and the finally. The backup service refuses to write over anything
        // that is already there, so a probe that did not clear its OWN reserved
        // name would report a recovery gap for every run after the first failure,
        // and the deployment would be red for a stale 12-byte file rather than for
        // anything about its ability to recover.
        file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . BackupRoundTripObserver::PROBE_ARCHIVE,
            'stale probe',
        );
        file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . BackupRoundTripObserver::PROBE_ARCHIVE . '.altered',
            'stale copy',
        );

        $observation = self::observer()->observe(self::shippedService(), $this->destination());

        self::assertTrue(
            $observation->isAdmissibleAsProof(),
            'a stale artefact under the reserved probe name must not be reported as a '
                . 'recovery gap',
        );
        self::assertStringContainsString('restored byte for byte', $observation->detail);
        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*'));
    }

    #[Test]
    public function itWritesToTheDeploymentsOwnDestinationRatherThanSomewhereItChose(): void
    {
        // A round trip against a temporary directory proves the algorithm and not
        // the deployment: an unwritable backup destination is one of the commonest
        // ways a recovery capability is absent while every binding reads clean.
        $service = self::shippedService();
        $seen = [];

        $watcher = new class ($service, $seen) implements BackupServiceInterface {
            /** @param list<string> $seen */
            public function __construct(
                private readonly BackupServiceInterface $inner,
                public array $seen,
            ) {}

            public function backUp(string $archivePath, iterable $sources): BackupManifest
            {
                $this->seen[] = $archivePath;

                return $this->inner->backUp($archivePath, $sources);
            }

            public function verify(string $archivePath): BackupVerification
            {
                return $this->inner->verify($archivePath);
            }

            public function restore(string $archivePath, array $targets): RestoreReport
            {
                return $this->inner->restore($archivePath, $targets);
            }

            public function keyId(): string
            {
                return $this->inner->keyId();
            }
        };

        (void) self::observer()->observe($watcher, $this->destination());

        self::assertSame(
            [$this->directory . DIRECTORY_SEPARATOR . BackupRoundTripObserver::PROBE_ARCHIVE],
            $watcher->seen,
        );
    }

    // --- Deployments that bind cleanly and cannot recover ----------------------

    #[Test]
    public function noBoundServiceIsReportedAsNothingExercisedRatherThanAsAPass(): void
    {
        $observation = self::observer()->observe(null, null);

        self::assertFalse(
            $observation->present,
            'a deployment with no master key binds no backup service, and that is a gap, not a pass',
        );
        self::assertStringContainsString('no observed way to recover', $observation->detail);
    }

    #[Test]
    public function aServiceWithoutADestinationIsAlsoNothingExercised(): void
    {
        $observation = self::observer()->observe(self::shippedService(), null);

        self::assertFalse($observation->present);
    }

    #[Test]
    public function aDestinationThatCannotBeWrittenFailsTheSubjectThatRan(): void
    {
        // The distinction the observer must not collapse: the service was CALLED and
        // it refused, which is a subject that ran and failed rather than a run that
        // could not happen.
        $blocked = $this->directory . DIRECTORY_SEPARATOR . 'a-file-not-a-directory';
        file_put_contents($blocked, 'x');

        $observation = self::observer()->observe(
            self::shippedService(),
            new BackupDestination($blocked . DIRECTORY_SEPARATOR . 'below'),
        );

        self::assertFalse($observation->present);
        self::assertStringContainsString('bound but not usable', $observation->detail);
    }

    #[Test]
    public function aServiceThatWritesThePayloadInTheClearIsCaught(): void
    {
        $observation = self::observer()->observe(new UnsealedBackupService(), $this->destination());

        self::assertFalse($observation->present, 'an unsealed archive is not a backup this framework ships');
        self::assertStringContainsString('the archive conceals what it holds', $observation->detail);
        self::assertStringContainsString('writes unsealed', $observation->detail);
    }

    #[Test]
    public function aServiceThatAcceptsAnAlteredArchiveIsCaught(): void
    {
        $observation = self::observer()->observe(new CredulousBackupService(), $this->destination());

        self::assertFalse($observation->present);
        self::assertStringContainsString('an altered archive is refused', $observation->detail);
        self::assertStringContainsString('cannot tell a backup from one somebody edited', $observation->detail);
    }

    #[Test]
    public function aServiceThatDoesNotGiveThePayloadBackIsCaught(): void
    {
        $observation = self::observer()->observe(new ForgetfulBackupService(), $this->destination());

        self::assertFalse($observation->present);
        self::assertStringContainsString('what went in comes back', $observation->detail);
    }

    // --- The probe that reads the fact ----------------------------------------

    #[Test]
    public function theRecoveryProbeIsCarriedByTheRoundTripAndNotByTheBinding(): void
    {
        $requirement = new RecoveryCapabilityProbe()->requirement();

        $required = [];

        foreach ($requirement->required as $fact) {
            $required[] = $fact->id;
        }

        self::assertSame([ObservationId::BackupRoundTripVerified], $required);
        self::assertSame(
            [ObservationId::BackupPrimitiveResolved],
            $requirement->supporting,
            'which class answers the backup contract is worth printing and decides nothing',
        );
    }

    // --- Helpers ---------------------------------------------------------------

    private static function observer(): BackupRoundTripObserver
    {
        return new BackupRoundTripObserver(new Randomizer(new Secure()));
    }

    private static function shippedService(): SealedArchiveBackupService
    {
        return new SealedArchiveBackupService(new ArchiveSeal(MasterKey::fromHex(bin2hex(random_bytes(32)))));
    }

    private function destination(): BackupDestination
    {
        return new BackupDestination($this->directory);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child)) {
                self::removeTree($child);

                continue;
            }

            if (is_file($child)) {
                unlink($child);
            }
        }

        rmdir($path);
    }
}
