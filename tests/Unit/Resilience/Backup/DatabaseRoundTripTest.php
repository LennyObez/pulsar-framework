<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Resilience\Backup;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\Driver;
use Pulsar\Database\Introspection\DatabaseIntrospector;
use Pulsar\Database\PdoConnection;
use Pulsar\Resilience\Backup\ArchiveSeal;
use Pulsar\Resilience\Backup\BackupEntry;
use Pulsar\Resilience\Backup\BackupException;
use Pulsar\Resilience\Backup\BackupSourceInterface;
use Pulsar\Resilience\Backup\DatabaseBackupSource;
use Pulsar\Resilience\Backup\DatabaseRestoreTarget;
use Pulsar\Resilience\Backup\SealedArchiveBackupService;
use Pulsar\Security\Crypto\MasterKey;

use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sodium_bin2hex;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * The rows go into an archive and come back out as the rows they were.
 *
 * WHY THIS FILE EXISTS. {@see DatabaseBackupSource} and
 * {@see DatabaseRestoreTarget} are the two classes that actually move a
 * deployment's data, and they shipped with no test of any kind: everything that
 * was covered was the archive FORMAT, exercised over synthetic byte strings.
 * A format that round-trips bytes perfectly says nothing about whether the rows
 * that went into it are the rows that come out, and a restore is discovered to
 * be wrong at the moment it is being relied on.
 *
 * EVERY CHECK GOES THROUGH THE REAL SEAL AND A REAL DATABASE ON BOTH SIDES. The
 * archive is written to a real file, and the rows are read back into a SECOND
 * connection that never saw the first -- the same rule the AI governance durable
 * stores are held to, and for the same reason: a store read through the object
 * that wrote it proves only that it remembers.
 */
#[CoversClass(DatabaseBackupSource::class)]
#[CoversClass(DatabaseRestoreTarget::class)]
final class DatabaseRoundTripTest extends TestCase
{
    /** Invalid UTF-8, so it travels through the archive under the binary marker. */
    private const string BINARY_VALUE = "\xff\xfe\xfd";

    /** @var non-empty-string */
    private string $directory;

    /** @var non-empty-string */
    private string $archive;

    private SealedArchiveBackupService $backups;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('pulsar-db-backup-', true);

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0o750, true);
        }

        $this->archive = $this->directory . DIRECTORY_SEPARATOR . 'archive.pulsarbk';
        $this->backups = new SealedArchiveBackupService(
            new ArchiveSeal(MasterKey::fromHex(sodium_bin2hex(random_bytes(32)))),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        self::removeTree($this->directory);
    }

    #[Test]
    public function theRowsComeBackIntoADatabaseThatNeverSawThem(): void
    {
        $origin = self::database();
        $origin->execute(
            'INSERT INTO ledger (id, party, amount, evidence) VALUES (:id, :party, :amount, :evidence)',
            ['id' => 1, 'party' => 'Ærø Bank', 'amount' => '10.50', 'evidence' => self::BINARY_VALUE],
        );
        $origin->execute(
            'INSERT INTO ledger (id, party, amount, evidence) VALUES (:id, :party, :amount, :evidence)',
            ['id' => 2, 'party' => 'second', 'amount' => '-3.25', 'evidence' => null],
        );

        $this->backups->backUp($this->archive, [
            new DatabaseBackupSource($origin, new DatabaseIntrospector($origin)),
        ]);

        $recovered = self::database();
        $report = $this->backups->restore($this->archive, [new DatabaseRestoreTarget($recovered)]);

        self::assertTrue($report->complete(), 'every entry in the archive must have found a target');

        $rows = $recovered->query('SELECT id, party, amount, evidence FROM ledger ORDER BY id')->rows;

        self::assertCount(2, $rows);
        self::assertSame('Ærø Bank', $rows[0]->data['party']);
        self::assertSame('10.50', $rows[0]->data['amount']);
        self::assertSame(
            self::BINARY_VALUE,
            $rows[0]->data['evidence'],
            'a column holding bytes that are not text must survive the round trip as those bytes',
        );
        self::assertSame('second', $rows[1]->data['party']);
        self::assertNull($rows[1]->data['evidence']);
    }

    #[Test]
    public function aPopulatedTableIsRefusedRatherThanMerged(): void
    {
        $origin = self::database();
        $origin->execute('INSERT INTO ledger (id, party, amount) VALUES (1, :party, :amount)', [
            'party' => 'the one that was backed up',
            'amount' => '1.00',
        ]);

        $this->backups->backUp($this->archive, [
            new DatabaseBackupSource($origin, new DatabaseIntrospector($origin)),
        ]);

        $live = self::database();
        $live->execute('INSERT INTO ledger (id, party, amount) VALUES (9, :party, :amount)', [
            'party' => 'the row that is already live',
            'amount' => '99.00',
        ]);

        try {
            $this->backups->restore($this->archive, [new DatabaseRestoreTarget($live)]);
            self::fail('restoring into a populated table would merge two data sets rather than recover one');
        } catch (BackupException $refusal) {
            self::assertStringContainsString('--replace', $refusal->getMessage());
        }

        $rows = $live->query('SELECT party FROM ledger')->rows;

        self::assertCount(1, $rows, 'the refusal must leave the live table exactly as it found it');
        self::assertSame('the row that is already live', $rows[0]->data['party']);
    }

    #[Test]
    public function aBinaryMarkerThatIsNotBase64IsRefusedRatherThanRestoredAsNothing(): void
    {
        // The seal proves the archive was not modified after it was written; it
        // proves nothing about the producer. An entry whose binary marker does not
        // decode is a producer that got the encoding wrong, and the per-entry digest
        // matches perfectly -- so this is the one corruption no other check in the
        // module can see. Substituting the empty string would put a blank where a
        // signature, a document or a key had been, in a restore nobody re-reads.
        $this->backups->backUp($this->archive, [self::ndjson(
            '{"pulsar_entry":"table","table":"ledger","ordered_by":"id"}' . "\n"
                . '{"row":{"id":1,"party":"p","amount":"1.00","evidence":{"pulsar_b64":"not base64 at all!"}}}' . "\n",
        )]);

        $recovered = self::database();

        try {
            $this->backups->restore($this->archive, [new DatabaseRestoreTarget($recovered)]);
            self::fail('a binary column that cannot be decoded must refuse the restore, not empty the column');
        } catch (BackupException $refusal) {
            self::assertStringContainsString('evidence', $refusal->getMessage());
        }

        self::assertSame(
            [],
            $recovered->query('SELECT id FROM ledger')->rows,
            'nothing may be written from an entry the target refused',
        );
    }

    // --- Helpers ---------------------------------------------------------------

    private static function database(): PdoConnection
    {
        $connection = new PdoConnection(
            connectionName: 'backup-round-trip',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $connection->execute(
            'CREATE TABLE ledger (id INTEGER PRIMARY KEY, party TEXT NOT NULL, amount TEXT NOT NULL, evidence BLOB)',
        );

        return $connection;
    }

    /**
     * A source that yields one `database/ledger.ndjson` entry of exactly these bytes.
     */
    private static function ndjson(string $content): BackupSourceInterface
    {
        return new class ($content) implements BackupSourceInterface {
            public function __construct(private readonly string $content) {}

            public function id(): string
            {
                return 'database';
            }

            public function describe(): string
            {
                return 'a hand-written table entry';
            }

            public function entries(): iterable
            {
                yield new BackupEntry('ledger.ndjson', [$this->content]);
            }
        };
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
