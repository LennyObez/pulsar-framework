<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\Control\ExecutedSubject;
use Pulsar\Compliance\Control\Measurement;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupEntry;
use Pulsar\Resilience\Backup\BackupServiceInterface;
use Pulsar\Resilience\Backup\BackupSourceInterface;
use Pulsar\Resilience\Backup\InMemoryRestoreTarget;
use Random\Randomizer;
use Throwable;

use function count;
use function file_get_contents;
use function file_put_contents;
use function hash_equals;
use function implode;
use function is_file;
use function sprintf;
use function str_contains;
use function strlen;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Whether this deployment can actually take a backup and get it back.
 *
 * WHY THIS EXISTS. `docs/compliance.md` recorded, for several releases, that
 * there was no backup or restore primitive in `src/` at all, and that NIST CSF
 * RC.RP "stays red until the primitive exists". Building the primitive does not
 * on its own turn that page green, and the reason is the defect ADR-0041
 * recorded: a control satisfied because a class is bound is satisfied by a
 * lookup. `backup_primitive_resolved` — the fact that was already in the
 * vocabulary — answers "which class implements the backup contract", and the
 * honest answer to that question on a broken deployment is the same as on a
 * working one.
 *
 * The distance between the two is not small. A deployment can bind
 * {@see BackupServiceInterface} and still be unable to back anything up because
 * the destination directory is not writable, because the key hierarchy will not
 * derive, because the filesystem the archive lands on truncates writes, or
 * because a future service implementation writes an archive it cannot read back.
 * Every one of those leaves the binding immaculate, and every one of them is
 * discovered during the recovery it was supposed to support.
 *
 * WHAT IT RUNS, in order, all six against the live service:
 *
 *   1. seal a synthetic payload into a REAL archive on the REAL destination
 *   2. verify the archive by reading it back and re-digesting every entry
 *   3. confirm the archive on disk does not contain the payload in the clear
 *   4. flip one byte and require the service to REFUSE the result
 *   5. restore the untouched archive and compare what came back, byte for byte
 *   6. remove the archive this check wrote
 *
 * Together they are the three properties the controls actually name: the copy
 * exists and can be made (1), it is tamper-evident and confidential (3, 4), and
 * it can be brought back (2, 5). A deployment that fails any of them has a backup
 * capability in name.
 *
 * THIS MEASUREMENT WRITES, and it is the only one in the evidence set that can
 * remove ALL of what it wrote. That is not tidiness: an archive this check left
 * behind would sit in the operator's backup directory looking exactly like a real
 * one, and would be picked up by a recovery. It is written under
 * {@see PROBE_ARCHIVE} rather than under the destination's own chronological
 * naming so it cannot be mistaken for one even in the window it exists, removal
 * runs in a `finally`, and a removal that fails is reported as a failed subject
 * rather than passed over.
 *
 * WHAT IT DELIBERATELY DOES NOT DO is back up or restore the deployment's own
 * data. A probe that restored the live database would be the disaster it is
 * rehearsing for, and one that backed up the whole estate would make every
 * compliance report as expensive as a backup run. The payload is 64 random bytes
 * from the framework's CSPRNG. What that costs in scope is stated rather than
 * hidden: the fact establishes that the recovery MECHANISM works end to end on
 * this host with this key, and the question of what a given archive contains is
 * answered by the manifest `pulsar backup:run` prints, which is an operator
 * artefact and is named as one in `docs/backup.md`.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class BackupRoundTripObserver
{
    /**
     * The file name this check writes under.
     *
     * Deliberately not the destination's chronological archive name: an operator
     * reaching for "the newest archive" during an incident must not find a 64-byte
     * synthetic one, and `pulsar backup:verify` globs for the real extension.
     */
    public const string PROBE_ARCHIVE = 'compliance-round-trip.probe';

    /** The entry name inside the probe archive. */
    private const string PROBE_ENTRY = 'round-trip.bin';

    /** The source id, and therefore the first path segment of the entry. */
    private const string PROBE_SOURCE = 'probe';

    /** What the measurement names as having been exercised, in the report. */
    private const string SUBJECT = 'the live backup and restore path';

    /** Bytes of synthetic payload. Large enough to be recognisable, small enough to be free. */
    private const int PAYLOAD_BYTES = 64;

    public function __construct(
        private Randomizer $randomizer,
    ) {}

    /**
     * Exercise the round trip, or report that there was nothing to exercise.
     *
     * @param BackupServiceInterface|null $backups     The service that would take this
     *        deployment's backups, resolved by the composition root. Null when nothing is
     *        bound — which is what a deployment with no master key looks like, because
     *        {@see \Pulsar\Core\Wiring\BackupWiring} fails closed rather than writing an
     *        unsealed archive. That is a fact, not a pass
     * @param BackupDestination|null      $destination The directory the deployment's real
     *        archives go to. The probe writes there ON PURPOSE: a round trip against a
     *        temporary directory would prove the algorithm and not the deployment, and an
     *        unwritable backup destination is one of the most common ways a backup
     *        capability is absent while every binding reads clean
     */
    #[NoDiscard]
    public function observe(?BackupServiceInterface $backups, ?BackupDestination $destination): Observation
    {
        if ($backups === null || $destination === null) {
            return Observation::measured(
                ObservationId::BackupRoundTripVerified,
                Measurement::couldNotRun(
                    self::SUBJECT,
                    'No backup service is in service, so nothing was exercised and this deployment '
                        . 'has no observed way to recover from data loss.',
                ),
                self::class,
            );
        }

        return Observation::measured(
            ObservationId::BackupRoundTripVerified,
            $this->exercise($backups, $destination),
            self::class,
        );
    }

    /**
     * Run the subjects, removing whatever was written.
     *
     * A throw from `backUp()` is reported as a subject that RAN and failed, not as
     * a run that could not happen: the service was called and it refused. That is
     * the distinction an unwritable destination falls on, and collapsing it would
     * hide exactly the deployment this class was written for.
     */
    private function exercise(BackupServiceInterface $backups, BackupDestination $destination): Measurement
    {
        $payload = $this->randomizer->getBytes(self::PAYLOAD_BYTES);
        $archive = $destination->directory . DIRECTORY_SEPARATOR . self::PROBE_ARCHIVE;
        $tampered = $archive . '.altered';

        // Clear the two names this check owns before writing them. The backup
        // service refuses a destination that already holds a file -- it will not
        // destroy an archive to make room for another -- so without this a run
        // whose removal failed once (a read-only volume, a crash between the write
        // and the `finally`) would make every later run report a recovery gap, and
        // the deployment would be red for a stale probe artefact rather than for
        // anything about its ability to recover. These two paths are reserved for
        // this class and are not archive names: nothing an operator holds is under
        // them, which is exactly why the removal is safe here and nowhere else.
        self::remove($archive);
        self::remove($tampered);

        try {
            $manifest = $backups->backUp($archive, [self::source($payload)]);
        } catch (Throwable $failure) {
            self::remove($archive);
            self::remove($tampered);

            return Measurement::completed(
                self::SUBJECT,
                [ExecutedSubject::failed(
                    'a backup is taken',
                    sprintf(
                        'The backup service refused to write an archive: %s. Nothing this '
                            . 'deployment holds can be recovered by it.',
                        $failure->getMessage(),
                    ),
                )],
                sprintf('The backup service is bound but not usable: backUp() failed with %s.', $failure->getMessage()),
            );
        }

        $results = [];

        try {
            $results[] = ExecutedSubject::passed(
                'a backup is taken',
                sprintf(
                    'A sealed archive of %d byte(s) was written to the deployment\'s own backup '
                        . 'destination under archive key %s.',
                    $manifest->sealedBytes,
                    $manifest->keyId,
                ),
            );

            $results[] = self::archiveVerifies($backups, $archive);
            $results[] = self::archiveConcealsPayload($archive, $payload);
            $results[] = self::alteredArchiveRefused($backups, $archive, $tampered);
            $results[] = self::payloadComesBack($backups, $archive, $payload);
        } finally {
            $results[] = self::archiveRemoved($archive, $tampered);
        }

        return Measurement::completed(self::SUBJECT, $results, self::describe($results));
    }

    /**
     * The source the probe backs up: one entry of synthetic bytes.
     */
    private static function source(string $payload): BackupSourceInterface
    {
        // The ids travel as constructor arguments rather than being read off the
        // enclosing class: an anonymous class nested in another class does not get
        // that class's private scope, and reaching for it would only compile
        // because the constants happened to be public.
        return new class (self::PROBE_SOURCE, self::PROBE_ENTRY, $payload) implements BackupSourceInterface {
            /**
             * @param non-empty-string $sourceId
             * @param non-empty-string $entryName
             */
            public function __construct(
                private readonly string $sourceId,
                private readonly string $entryName,
                private readonly string $payload,
            ) {}

            public function id(): string
            {
                return $this->sourceId;
            }

            public function describe(): string
            {
                return 'synthetic bytes written by the compliance round-trip check';
            }

            public function entries(): iterable
            {
                yield new BackupEntry($this->entryName, [$this->payload]);
            }
        };
    }

    /**
     * An archive nobody can read back is a file, not a backup.
     *
     * @param non-empty-string $archive
     */
    private static function archiveVerifies(BackupServiceInterface $backups, string $archive): ExecutedSubject
    {
        try {
            $verification = $backups->verify($archive);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'the archive reads back',
                sprintf('Verifying the archive that had just been written threw: %s.', $failure->getMessage()),
            );
        }

        return $verification->intact()
            ? ExecutedSubject::passed(
                'the archive reads back',
                sprintf(
                    'Every sealed chunk authenticated, the stream carried its final tag, and %d '
                        . 'entry digest(s) matched.',
                    $verification->entryCount(),
                ),
            )
            : ExecutedSubject::failed(
                'the archive reads back',
                sprintf('The archive this deployment had just written did not read back: %s', $verification->refusal ?? ''),
            );
    }

    /**
     * The bytes on disk must not carry the payload.
     *
     * Read from the FILE rather than through the service, for the reason
     * {@see TokenVaultObserver} reads the token store directly: a round trip
     * through the object that wrote the archive can only prove it decrypts its own
     * output, never that what rests on the volume conceals anything.
     *
     * @param non-empty-string $archive
     */
    private static function archiveConcealsPayload(string $archive, string $payload): ExecutedSubject
    {
        $bytes = @file_get_contents($archive);

        if ($bytes === false) {
            return ExecutedSubject::failed(
                'the archive conceals what it holds',
                'The archive could not be read back off the volume it was written to.',
            );
        }

        return str_contains($bytes, $payload)
            ? ExecutedSubject::failed(
                'the archive conceals what it holds',
                'The archive contains its payload in the clear: this deployment writes unsealed '
                    . 'backups, and anyone who can read the file can read everything in it.',
            )
            : ExecutedSubject::passed(
                'the archive conceals what it holds',
                sprintf(
                    'The %d bytes at rest carry none of the %d-byte payload they stand for.',
                    strlen($bytes),
                    strlen($payload),
                ),
            );
    }

    /**
     * One byte changed must be refused, not repaired and not reported as a warning.
     *
     * The subject nothing weaker can establish. A backup that opens after being
     * modified is a backup an attacker can choose the contents of, and a restore
     * is performed under pressure by someone with privileges, which is exactly
     * when nobody is checking.
     *
     * @param non-empty-string $archive
     * @param non-empty-string $tampered
     */
    private static function alteredArchiveRefused(
        BackupServiceInterface $backups,
        string $archive,
        string $tampered,
    ): ExecutedSubject {
        $bytes = @file_get_contents($archive);

        if ($bytes === false || strlen($bytes) < 2) {
            return ExecutedSubject::failed(
                'an altered archive is refused',
                'The archive could not be re-read, so tamper-evidence was not established.',
            );
        }

        // The last byte lies inside the final sealed chunk on any archive this
        // service writes, so the change always lands in authenticated ciphertext
        // rather than in trailing padding. XORed as a single-byte STRING rather
        // than through chr(ord(...)): the result is a byte by construction, with no
        // integer round trip that could leave the codepoint range.
        $offset = strlen($bytes) - 1;
        $bytes[$offset] = $bytes[$offset] ^ '';

        if (@file_put_contents($tampered, $bytes) === false) {
            return ExecutedSubject::failed(
                'an altered archive is refused',
                'A modified copy could not be written, so tamper-evidence was not established.',
            );
        }

        try {
            $verification = $backups->verify($tampered);
        } catch (Throwable $failure) {
            // A throw is a refusal too, and a louder one.
            return ExecutedSubject::passed(
                'an altered archive is refused',
                sprintf('An archive with one byte changed was refused: %s', $failure->getMessage()),
            );
        }

        return $verification->intact()
            ? ExecutedSubject::failed(
                'an altered archive is refused',
                'An archive with one byte changed was reported intact. This deployment cannot tell '
                    . 'a backup from one somebody edited, so nothing it restores is evidence.',
            )
            : ExecutedSubject::passed(
                'an altered archive is refused',
                sprintf('An archive with one byte changed was refused: %s', $verification->refusal ?? ''),
            );
    }

    /**
     * A backup nobody has restored is a belief. This is the part that stops it
     * being one.
     */
    /**
     * @param non-empty-string $archive
     */
    private static function payloadComesBack(
        BackupServiceInterface $backups,
        string $archive,
        string $payload,
    ): ExecutedSubject {
        $target = new InMemoryRestoreTarget('compliance-round-trip');

        try {
            $report = $backups->restore($archive, [$target]);
        } catch (Throwable $failure) {
            return ExecutedSubject::failed(
                'what went in comes back',
                sprintf('Restoring the archive that had just been verified threw: %s.', $failure->getMessage()),
            );
        }

        $recovered = $target->contentOf(self::PROBE_SOURCE . '/' . self::PROBE_ENTRY);

        if ($recovered === null) {
            return ExecutedSubject::failed(
                'what went in comes back',
                sprintf(
                    'The restore reported %d entr(ies) and none of them was the one that was backed '
                        . 'up, so the archive does not give back what it was given.',
                    $report->restoredCount(),
                ),
            );
        }

        return hash_equals($payload, $recovered)
            ? ExecutedSubject::passed(
                'what went in comes back',
                sprintf(
                    'The restored entry matched the %d bytes that were backed up, byte for byte, '
                        . 'through the deployment\'s own seal and archive format.',
                    strlen($payload),
                ),
            )
            : ExecutedSubject::failed(
                'what went in comes back',
                sprintf(
                    'The restore returned %d byte(s) that differ from the %d that went in, so a '
                        . 'recovery from this deployment\'s archives would not reproduce its data.',
                    strlen($recovered),
                    strlen($payload),
                ),
            );
    }

    /**
     * Both files this check wrote must go away again.
     *
     * Asserted rather than assumed, and reported when it fails: a synthetic
     * archive left in the operator's backup directory is indistinguishable from a
     * real one at the moment it matters most.
     */
    private static function archiveRemoved(string $archive, string $tampered): ExecutedSubject
    {
        self::remove($archive);
        self::remove($tampered);

        $lingering = [];

        if (is_file($archive)) {
            $lingering[] = $archive;
        }

        if (is_file($tampered)) {
            $lingering[] = $tampered;
        }

        return $lingering === []
            ? ExecutedSubject::passed(
                'the probe archive is removed',
                'The synthetic archive this check wrote was removed from the backup destination.',
            )
            : ExecutedSubject::failed(
                'the probe archive is removed',
                sprintf(
                    'The synthetic archive this check wrote could not be removed; %s remain(s) in the '
                        . 'backup destination and must be deleted by hand.',
                    implode(', ', $lingering),
                ),
            );
    }

    private static function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The sentence the report prints, naming what failed when something did.
     *
     * @param list<ExecutedSubject> $results
     *
     * @return non-empty-string
     */
    private static function describe(array $results): string
    {
        $failed = [];

        foreach ($results as $result) {
            if (!$result->passed) {
                $failed[] = $result->name . ': ' . $result->detail;
            }
        }

        return $failed === []
            ? sprintf(
                'A synthetic payload was sealed into an archive on this deployment\'s own backup '
                    . 'destination, read back and re-digested, found to conceal its content at rest, '
                    . 'refused when one byte was changed, restored byte for byte, and the archive '
                    . 'removed again. %d subject(s) ran.',
                count($results),
            )
            : 'This deployment cannot demonstrate a recoverable backup — ' . implode(' | ', $failed);
    }
}
