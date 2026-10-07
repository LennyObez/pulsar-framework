<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Backup;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Backup\ArchiveFormat;
use Pulsar\Resilience\Backup\BackupDestination;
use Pulsar\Resilience\Backup\BackupPlan;
use Pulsar\Resilience\Backup\BackupVerification;
use Pulsar\Resilience\Backup\EntryDigest;

use function fclose;
use function fopen;
use function fwrite;
use function rewind;
use function sodium_crypto_generichash;
use function str_repeat;
use function str_starts_with;
use function strlen;

use const DIRECTORY_SEPARATOR;

/**
 * The small parts of the archive format that everything above them trusts.
 *
 * Each case here fixes a property some other class assumes without being able to
 * see it: the digest accumulator keeps what the archive recorded, a destination
 * always yields a path, a refusal always carries a sentence, and a fixed-width
 * read is exact. None of them was covered before, and each is the kind of thing
 * that fails silently rather than loudly.
 */
#[CoversClass(EntryDigest::class)]
#[CoversClass(ArchiveFormat::class)]
#[CoversClass(BackupDestination::class)]
#[CoversClass(BackupVerification::class)]
#[CoversClass(BackupPlan::class)]
final class ArchiveInternalsTest extends TestCase
{
    // --- EntryDigest -----------------------------------------------------------

    #[Test]
    public function theDigestIsBlake2bOverEverythingItWasHanded(): void
    {
        $digest = new EntryDigest();
        $digest->add('alpha');
        $digest->add('beta');

        self::assertSame(sodium_crypto_generichash('alphabeta', '', 32), $digest->digest());
        self::assertSame(9, $digest->bytes());
        self::assertTrue($digest->closed());
    }

    #[Test]
    public function closingAgainDoesNotForgetWhatTheArchiveRecorded(): void
    {
        // THE DEFECT THIS PINS. close() took the recorded digest and byte count as
        // optional arguments and assigned them unconditionally, so a later bare
        // close() -- and digest() performs one -- reset both to null. The archive
        // reader reads the recorded pair out of this object and treats a null as
        // "this entry has no end marker", which refuses an archive that is intact.
        // Nothing triggered it only because assertEntryIntact() happened to read the
        // recorded values into locals before it asked for the digest.
        $recorded = sodium_crypto_generichash('alpha', '', 32);

        $digest = new EntryDigest();
        $digest->add('alpha');
        $digest->close($recorded, 5);

        (void) $digest->digest();

        self::assertSame($recorded, $digest->recordedDigest());
        self::assertSame(5, $digest->recordedBytes());
    }

    #[Test]
    public function anUnclosedDigestHasRecordedNothing(): void
    {
        $digest = new EntryDigest();
        $digest->add('alpha');

        self::assertFalse($digest->closed());
        self::assertNull($digest->recordedDigest());
        self::assertNull($digest->recordedBytes());
    }

    // --- ArchiveFormat ---------------------------------------------------------

    #[Test]
    public function readExactlyReturnsExactlyWhatWasAskedForOrNothing(): void
    {
        $handle = fopen('php://memory', 'r+b');

        self::assertIsResource($handle);

        fwrite($handle, 'abcdefghij');
        rewind($handle);

        self::assertSame('abcde', ArchiveFormat::readExactly($handle, 5));
        self::assertSame('fghij', ArchiveFormat::readExactly($handle, 5));
        self::assertNull(ArchiveFormat::readExactly($handle, 1), 'a stream that ended reads as nothing, not as empty');

        fclose($handle);
    }

    #[Test]
    public function readExactlyOfNothingIsNotARead(): void
    {
        $handle = fopen('php://memory', 'r+b');

        self::assertIsResource($handle);

        fwrite($handle, 'abc');
        rewind($handle);

        // Asking for zero -- or, through an arithmetic slip, for less than zero --
        // must not reach fread(), which refuses a non-positive length.
        self::assertSame('', ArchiveFormat::readExactly($handle, 0));
        self::assertSame('', ArchiveFormat::readExactly($handle, -1));
        self::assertSame('abc', ArchiveFormat::readExactly($handle, 3));

        fclose($handle);
    }

    #[Test]
    public function theHeaderRoundTripsAndAnythingElseIsRefused(): void
    {
        $header = ArchiveFormat::header('deadbeefdeadbeef', '2026-09-02T10:00:00+00:00');
        $parsed = ArchiveFormat::parseHeader($header);

        self::assertNotNull($parsed);
        self::assertSame(ArchiveFormat::VERSION, $parsed['format']);
        self::assertSame('deadbeefdeadbeef', $parsed['key_id']);
        self::assertSame('2026-09-02T10:00:00+00:00', $parsed['created_at']);

        self::assertNull(ArchiveFormat::parseHeader('not json'));
        self::assertNull(ArchiveFormat::parseHeader('[]'));
        self::assertNull(ArchiveFormat::parseHeader('{"format":1,"key_id":"","created_at":"x"}'));
        self::assertNull(ArchiveFormat::parseHeader('{"format":"1","key_id":"a","created_at":"x"}'));
    }

    #[Test]
    public function lengthPrefixesReadBackAsTheyWereWrittenAndRefuseAShortBuffer(): void
    {
        self::assertSame(70_000, ArchiveFormat::readUint32(ArchiveFormat::uint32(70_000), 0));
        self::assertSame(5_000_000_000, ArchiveFormat::readUint64(ArchiveFormat::uint64(5_000_000_000), 0));
        self::assertNull(ArchiveFormat::readUint32('ab', 0));
        self::assertNull(ArchiveFormat::readUint64('abcd', 0));
    }

    // --- BackupDestination -----------------------------------------------------

    #[Test]
    public function everyArchiveNameSortsChronologicallyAndCarriesTheExtension(): void
    {
        $destination = new BackupDestination('/var/backups');

        $earlier = $destination->nextArchive(new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC')));
        $later = $destination->nextArchive(new DateTimeImmutable('2026-06-01T13:45:12', new DateTimeZone('UTC')));

        self::assertLessThan($later, $earlier, 'an ls in the destination is meant to read as a backup history');
        self::assertStringEndsWith(BackupDestination::EXTENSION, $later);
        self::assertStringContainsString('2026-06-01T134512Z', $later);
    }

    #[Test]
    public function aTrailingSeparatorOnTheDirectoryDoesNotDoubleUp(): void
    {
        $at = new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC'));

        self::assertSame(
            new BackupDestination('/var/backups')->nextArchive($at),
            new BackupDestination('/var/backups/')->nextArchive($at),
        );
    }

    #[Test]
    public function anArchiveNameIsAlwaysAPathEvenWhenTheDirectoryIsNot(): void
    {
        // A destination that came through as the empty string still has to produce a
        // name: the callers treat the result as a path, and an empty one would be
        // handed to fopen() as the archive to write.
        $name = new BackupDestination('')->nextArchive();

        self::assertNotSame('', $name);
        self::assertTrue(str_starts_with($name, DIRECTORY_SEPARATOR));
    }

    // --- BackupVerification ----------------------------------------------------

    #[Test]
    public function aRefusalAlwaysSaysSomething(): void
    {
        // The reason arrives as an exception message, and an exception is free to
        // carry an empty one. A verification whose refusal is the empty string prints
        // as a blank line in `pulsar backup:verify` and as an empty detail in the
        // compliance report -- an archive refused for no stated reason.
        $refused = BackupVerification::refused('/var/backups/a.pulsarbk', '');

        self::assertFalse($refused->intact());
        self::assertNotSame('', (string) $refused->refusal);
    }

    #[Test]
    public function theVerdictIsDerivedFromTheRefusalRatherThanPassedIn(): void
    {
        $intact = BackupVerification::readBack(
            '/var/backups/a.pulsarbk',
            new DateTimeImmutable('2026-01-01T00:00:00', new DateTimeZone('UTC')),
            'deadbeefdeadbeef',
            [],
            128,
        );

        self::assertTrue($intact->intact());
        self::assertNull($intact->refusal);
        self::assertSame(0, $intact->entryCount());
        self::assertFalse(BackupVerification::refused('/var/backups/a.pulsarbk', 'it stops early')->intact());
    }

    // --- BackupPlan ------------------------------------------------------------

    #[Test]
    public function thePlanNamesWhatIsNotInAnArchive(): void
    {
        // Stated in code rather than only in prose, because an operator has to be
        // able to read the boundary off the thing that enforces it.
        $plan = new BackupPlan([], []);

        self::assertSame([], $plan->coverage());
        self::assertFalse($plan->includes('audit'));
        self::assertArrayHasKey('The master key', BackupPlan::NOT_COVERED);
        self::assertArrayHasKey('Offsite replication', BackupPlan::NOT_COVERED);
        self::assertArrayHasKey('Retention and rotation', BackupPlan::NOT_COVERED);
    }

    #[Test]
    public function aFrameNeverExceedsOneChunkSoTheReaderNeverBuffersMoreThanTwo(): void
    {
        // The reader refuses a frame longer than one chunk before allocating it, so
        // the writer must never emit one. The entry-content path re-chunks; this
        // pins the constant relationship the two sides agree on.
        self::assertSame(65_536, ArchiveFormat::CHUNK_SIZE);
        self::assertGreaterThan(ArchiveFormat::CHUNK_SIZE, ArchiveFormat::MAX_SEALED_CHUNK_BYTES);
        self::assertLessThan(ArchiveFormat::CHUNK_SIZE, ArchiveFormat::MAX_HEADER_BYTES);
        self::assertSame(32, ArchiveFormat::DIGEST_BYTES);
        self::assertSame(8, strlen(ArchiveFormat::MAGIC));
        self::assertSame(ArchiveFormat::CHUNK_SIZE, strlen(str_repeat('x', ArchiveFormat::CHUNK_SIZE)));
    }
}
