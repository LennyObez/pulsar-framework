<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Backup;

use Generator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Resilience\Backup\ArchivedEntry;
use Pulsar\Resilience\Backup\ArchiveFormat;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupEntry;
use Pulsar\Resilience\Backup\BackupException;
use Pulsar\Resilience\Backup\BackupManifest;
use Pulsar\Resilience\Backup\BackupSourceInterface;
use Pulsar\Resilience\Backup\BackupVerification;
use Pulsar\Resilience\Backup\FileTreeRestoreTarget;
use Pulsar\Resilience\Backup\InMemoryRestoreTarget;
use Pulsar\Resilience\Backup\RestoredEntry;
use Pulsar\Resilience\Backup\RestoreReport;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Resilience\Backup\SealedArchiveWriter;
use Pulsar\Security\Crypto\MasterKey;
use RuntimeException;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sodium_bin2hex;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The archive is sealed, and an archive that is not intact does not come back.
 *
 * WHY THIS FILE EXISTS. The backup subsystem shipped with no test of any kind.
 * `BackupServiceInterface` states four guarantees an implementation must hold --
 * the archive is sealed, modification is refused, nothing is buffered whole, and
 * the wiring fails closed -- and nothing anywhere exercised the first two. A
 * primitive whose entire value is that a restore refuses a tampered copy cannot be
 * shipped to a regulated deployment on the strength of a docblock saying it does.
 *
 * EVERY CHECK HERE GOES THROUGH THE REAL SEAL, derived from a real
 * {@see MasterKey} under the real sub-key pair, and reads the real bytes off a
 * real file. There is no fake service and no in-memory archive: a format verified
 * against a mock of itself is verified against nothing.
 */
#[CoversClass(SealedArchiveBackupService::class)]
#[CoversClass(SealedArchiveWriter::class)]
#[CoversClass(ArchiveSeal::class)]
#[CoversClass(ArchiveFormat::class)]
#[CoversClass(ArchivedEntry::class)]
#[CoversClass(BackupEntry::class)]
#[CoversClass(BackupException::class)]
#[CoversClass(BackupManifest::class)]
#[CoversClass(BackupVerification::class)]
#[CoversClass(RestoreReport::class)]
#[CoversClass(RestoredEntry::class)]
#[CoversClass(InMemoryRestoreTarget::class)]
#[CoversClass(FileTreeRestoreTarget::class)]
final class SealedArchiveBackupServiceTest extends TestCase
{
    /** @var non-empty-string */
    private string $directory;

    private SealedArchiveBackupService $backups;

    /** @var non-empty-string */
    private string $archive;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pulsar-backup-', true);

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o750, true);
        }

        $this->backups = self::serviceFor(sodium_bin2hex(random_bytes(32)));
        $this->archive = $this->directory . DIRECTORY_SEPARATOR . 'archive.pulsarbk';
    }

    #[Override]
    protected function tearDown(): void
    {
        self::removeTree($this->directory);
    }

    // --- The round trip --------------------------------------------------------

    #[Test]
    public function whatGoesInComesBackByteForByte(): void
    {
        // Deliberately larger than one sealed chunk: a format that only ever holds
        // one chunk is a format whose chunk boundary is never exercised, and the
        // boundary is where a streaming reader loses bytes.
        $large = random_bytes(ArchiveFormat::CHUNK_SIZE * 2 + 17);
        $small = 'a short one';

        $manifest = $this->backups->backUp($this->archive, [
            self::source('one', ['big.bin' => $large, 'small.txt' => $small]),
        ]);

        self::assertSame(2, $manifest->entryCount());
        self::assertTrue($manifest->covers('one'));
        self::assertSame(strlen($large) + strlen($small), $manifest->contentBytes());

        $target = new InMemoryRestoreTarget('memory');
        $report = $this->backups->restore($this->archive, [$target]);

        self::assertTrue($report->complete());
        self::assertSame($large, $target->contentOf('one/big.bin'));
        self::assertSame($small, $target->contentOf('one/small.txt'));
    }

    #[Test]
    public function anEntryWithNoContentSurvivesTheRoundTrip(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['empty.bin' => ''])]);

        $target = new InMemoryRestoreTarget('memory');
        $this->backups->restore($this->archive, [$target]);

        self::assertSame('', $target->contentOf('one/empty.bin'));
    }

    #[Test]
    public function verifyReadsEveryEntryBackAndRedigestsIt(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['a.txt' => 'alpha', 'b.txt' => 'beta'])]);

        $verification = $this->backups->verify($this->archive);

        self::assertTrue($verification->intact());
        self::assertNull($verification->refusal);
        self::assertSame(2, $verification->entryCount());
        self::assertSame($this->backups->keyId(), $verification->keyId);
    }

    #[Test]
    public function theManifestAndTheVerificationAgreeOnEveryEntryDigest(): void
    {
        $manifest = $this->backups->backUp($this->archive, [
            self::source('one', ['a.txt' => 'alpha', 'b.bin' => random_bytes(9_000)]),
        ]);

        $written = [];

        foreach ($manifest->entries as $entry) {
            $written[$entry->name] = [$entry->bytes, $entry->digestHex];
        }

        $readBack = [];

        foreach ($this->backups->verify($this->archive)->entries as $entry) {
            $readBack[$entry->name] = [$entry->bytes, $entry->digestHex];
        }

        // The writer and the reader run the same accumulator. If they ever stopped
        // agreeing, every archive this deployment holds would still verify against
        // itself and no longer against what was actually backed up.
        self::assertSame($written, $readBack);
    }

    // --- The seal --------------------------------------------------------------

    #[Test]
    public function theArchiveOnDiskCarriesNoneOfItsContentInTheClear(): void
    {
        $payload = 'PATIENT-4471 diagnosis withheld';

        $this->backups->backUp($this->archive, [self::source('records', ['note.txt' => $payload])]);

        $bytes = (string) file_get_contents($this->archive);

        self::assertFalse(strpos($bytes, $payload), 'the payload must not rest on the volume in the clear');
        self::assertFalse(strpos($bytes, 'note.txt'), 'entry names describe the estate and belong inside the seal');
    }

    #[Test]
    public function oneAlteredByteIsRefusedRatherThanRead(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['a.txt' => 'alpha'])]);

        self::flipLastByte($this->archive);

        $verification = $this->backups->verify($this->archive);

        self::assertFalse($verification->intact(), 'an archive somebody edited must not read back as intact');
        self::assertNotSame('', (string) $verification->refusal);
    }

    #[Test]
    public function restoringAnAlteredArchiveThrowsInsteadOfPuttingItBack(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['a.txt' => 'alpha'])]);

        self::flipLastByte($this->archive);

        $this->expectException(BackupException::class);

        $this->backups->restore($this->archive, [new InMemoryRestoreTarget('memory')]);
    }

    #[Test]
    public function rewritingTheHeaderBreaksEveryChunkUnderIt(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['a.txt' => 'alpha'])]);

        // The plaintext header travels as the AEAD's additional data. Changing the
        // recorded creation instant -- the field an assessor reads to decide whether
        // an archive predates an incident -- must break the seal, not pass silently.
        $bytes = (string) file_get_contents($this->archive);
        $at = strpos($bytes, '"created_at":"');

        self::assertIsInt($at);

        $offset = $at + strlen('"created_at":"');
        $bytes[$offset] = $bytes[$offset] === '9' ? '8' : '9';
        file_put_contents($this->archive, $bytes);

        self::assertFalse($this->backups->verify($this->archive)->intact());
    }

    #[Test]
    public function aTruncatedArchiveIsRefusedAsTruncated(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['a.txt' => str_repeat('x', 200_000)])]);

        $bytes = (string) file_get_contents($this->archive);
        file_put_contents($this->archive, substr($bytes, 0, strlen($bytes) - 4_096));

        self::assertFalse($this->backups->verify($this->archive)->intact());
    }

    #[Test]
    public function anArchiveCutExactlyOnAChunkBoundaryIsStillRefused(): void
    {
        // The case a per-chunk AEAD alone does not catch: every remaining chunk
        // authenticates, and only the missing FINAL tag says the file is a prefix.
        $this->backups->backUp($this->archive, [
            self::source('one', ['a.bin' => random_bytes(ArchiveFormat::CHUNK_SIZE * 3)]),
        ]);

        $bytes = (string) file_get_contents($this->archive);
        $preamble = strlen(ArchiveFormat::MAGIC) + 1;
        $headerLength = (int) ArchiveFormat::readUint32($bytes, $preamble);
        $firstChunkAt = $preamble + 4 + $headerLength + 24;
        $firstChunkLength = (int) ArchiveFormat::readUint32($bytes, $firstChunkAt);

        file_put_contents($this->archive, substr($bytes, 0, $firstChunkAt + 4 + $firstChunkLength));

        self::assertFalse($this->backups->verify($this->archive)->intact(), 'a prefix of an archive is not an archive');
    }

    #[Test]
    public function anArchiveSealedUnderAnotherKeyIsNamedRatherThanFailingToDecrypt(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['a.txt' => 'alpha'])]);

        $elsewhere = self::serviceFor(sodium_bin2hex(random_bytes(32)));

        $verification = $elsewhere->verify($this->archive);

        self::assertFalse($verification->intact());
        self::assertStringContainsString('sealed under archive key', (string) $verification->refusal);
    }

    #[Test]
    public function twoDeploymentsDeriveDifferentArchiveKeyIds(): void
    {
        $other = self::serviceFor(sodium_bin2hex(random_bytes(32)));

        self::assertNotSame($this->backups->keyId(), $other->keyId());
        self::assertSame($this->backups->keyId(), $this->backups->keyId());
    }

    #[Test]
    public function aFileThatIsNotAnArchiveIsRefusedByName(): void
    {
        file_put_contents($this->archive, 'this is a text file, not an archive');

        $verification = $this->backups->verify($this->archive);

        self::assertFalse($verification->intact());
        self::assertStringContainsString('not a readable Pulsar backup archive', (string) $verification->refusal);
    }

    #[Test]
    public function anArchiveThatIsNotThereIsRefusedRatherThanThrown(): void
    {
        // verify() returns a verdict per archive on purpose: an operator sweeping a
        // destination needs a result for every file, not an abort on the first bad one.
        $verification = $this->backups->verify($this->directory . DIRECTORY_SEPARATOR . 'absent.pulsarbk');

        self::assertFalse($verification->intact());
        self::assertNotSame('', (string) $verification->refusal);
    }

    // --- Refusals on the way in ------------------------------------------------

    #[Test]
    public function anArchiveThatIsAlreadyThereIsNeverWrittenOver(): void
    {
        $this->backups->backUp($this->archive, [self::source('one', ['first.txt' => 'the backup that already exists'])]);

        $before = (string) file_get_contents($this->archive);

        try {
            $this->backups->backUp($this->archive, [self::source('one', ['second.txt' => 'a later run'])]);
            self::fail('a backup must never be allowed to write over an archive that is already there');
        } catch (BackupException $refusal) {
            self::assertStringContainsString('already', $refusal->getMessage());
        }

        // The bytes, not just the presence: a truncate-then-fail would leave the
        // file there and the archive gone.
        self::assertSame($before, (string) file_get_contents($this->archive));

        $target = new InMemoryRestoreTarget('memory');
        $this->backups->restore($this->archive, [$target]);

        self::assertSame('the backup that already exists', $target->contentOf('one/first.txt'));
    }

    #[Test]
    public function aFailedBackupLeavesNoArchiveBehind(): void
    {
        $exploding = new class implements BackupSourceInterface {
            public function id(): string
            {
                return 'one';
            }

            public function describe(): string
            {
                return 'a source that fails halfway';
            }

            public function entries(): iterable
            {
                yield new BackupEntry('good.txt', ['fine']);
                yield new BackupEntry('bad.txt', (static function (): Generator {
                    yield 'partial';

                    throw new RuntimeException('the volume went away');
                })());
            }
        };

        try {
            $this->backups->backUp($this->archive, [$exploding]);
            self::fail('a source that failed mid-read must fail the backup');
        } catch (BackupException) {
            // A half-written file with a valid header is the most dangerous artefact
            // this class can leave: it sits exactly where the operator expects the backup.
            self::assertFileDoesNotExist($this->archive);
        }
    }

    #[Test]
    public function anUnsafeEntryNameIsRefusedRatherThanSanitised(): void
    {
        $this->expectException(BackupException::class);

        new BackupEntry('../../etc/shadow', ['x']);
    }

    #[Test]
    public function entryNamesThatCouldEscapeARestoreRootAreAllRefused(): void
    {
        $unsafe = ['', '.', '..', '/etc/passwd', 'a/../../b', 'C:/windows', "nul\x00byte", 'a\\b', './a', 'a//b'];

        foreach ($unsafe as $name) {
            self::assertFalse(BackupEntry::isSafeName($name), $name . ' must not be a usable entry name');
        }

        foreach (['a.txt', 'dir/a.txt', 'a..b/c.txt'] as $name) {
            self::assertTrue(BackupEntry::isSafeName($name), $name . ' is a safe relative path');
        }
    }

    // --- Restore routing -------------------------------------------------------

    #[Test]
    public function anEntryNoTargetClaimsIsNamedRatherThanDropped(): void
    {
        $this->backups->backUp($this->archive, [
            self::source('database', ['orders.ndjson' => '{}']),
            self::source('audit', ['audit.log' => 'chain']),
        ]);

        $target = new InMemoryRestoreTarget('memory', 'audit/');
        $report = $this->backups->restore($this->archive, [$target]);

        self::assertFalse($report->complete(), 'a restore that put half the archive back must say so');
        self::assertSame(['database/orders.ndjson'], $report->skipped);
        self::assertSame(1, $report->restoredCount());
        self::assertSame('audit/audit.log', $report->restored[0]->name);
        self::assertSame('memory', $report->restored[0]->targetId);
    }

    #[Test]
    public function aFileTreeTargetPutsTheFilesBackWhereTheyWere(): void
    {
        $this->backups->backUp($this->archive, [
            self::source('files', ['logo.png' => 'PNGDATA', 'nested/deep.txt' => 'deep']),
        ]);

        $root = $this->directory . DIRECTORY_SEPARATOR . 'restored';
        $this->backups->restore($this->archive, [new FileTreeRestoreTarget('files', $root)]);

        self::assertSame('PNGDATA', file_get_contents($root . DIRECTORY_SEPARATOR . 'logo.png'));
        self::assertSame(
            'deep',
            file_get_contents($root . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deep.txt'),
        );
    }

    #[Test]
    public function aFileTreeTargetRefusesToOverwriteUnlessAsked(): void
    {
        $this->backups->backUp($this->archive, [self::source('files', ['keep.txt' => 'from the archive'])]);

        $root = $this->directory . DIRECTORY_SEPARATOR . 'live';
        mkdir($root, 0o750, true);
        file_put_contents($root . DIRECTORY_SEPARATOR . 'keep.txt', 'the deployment is still using this');

        try {
            $this->backups->restore($this->archive, [new FileTreeRestoreTarget('files', $root)]);
            self::fail('restoring over a live file must be refused by default');
        } catch (BackupException $refusal) {
            self::assertStringContainsString('--overwrite', $refusal->getMessage());
            self::assertSame(
                'the deployment is still using this',
                file_get_contents($root . DIRECTORY_SEPARATOR . 'keep.txt'),
            );
        }

        $this->backups->restore(
            $this->archive,
            [new FileTreeRestoreTarget('files', $root)->withOverwrite(true)],
        );

        self::assertSame('from the archive', file_get_contents($root . DIRECTORY_SEPARATOR . 'keep.txt'));
    }

    // --- Helpers ---------------------------------------------------------------

    private static function serviceFor(string $hex): SealedArchiveBackupService
    {
        return new SealedArchiveBackupService(new ArchiveSeal(MasterKey::fromHex($hex)));
    }

    /**
     * @param non-empty-string      $id
     * @param array<string, string> $entries
     */
    private static function source(string $id, array $entries): BackupSourceInterface
    {
        return new class ($id, $entries) implements BackupSourceInterface {
            /**
             * @param non-empty-string      $sourceId
             * @param array<string, string> $entries
             */
            public function __construct(
                private readonly string $sourceId,
                private readonly array $entries,
            ) {}

            public function id(): string
            {
                return $this->sourceId;
            }

            public function describe(): string
            {
                return 'a synthetic source';
            }

            public function entries(): iterable
            {
                foreach ($this->entries as $name => $content) {
                    if ($name === '') {
                        continue;
                    }

                    yield new BackupEntry($name, [$content]);
                }
            }
        };
    }

    private static function flipLastByte(string $path): void
    {
        $bytes = (string) file_get_contents($path);
        $offset = strlen($bytes) - 1;
        $bytes[$offset] = $bytes[$offset] ^ "\x01";
        file_put_contents($path, $bytes);
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
