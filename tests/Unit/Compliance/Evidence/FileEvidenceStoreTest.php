<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\EvidenceChainHead;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceWriteFailedException;
use Pulsar\Compliance\Evidence\FileEvidenceStore;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Security\Audit\AuditChainState;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function substr_count;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const FILE_APPEND;

#[CoversClass(FileEvidenceStore::class)]
#[CoversClass(EvidenceChainHead::class)]
final class FileEvidenceStoreTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pulsar_evidence_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            $this->cleanDir($this->dir);
        }
    }

    #[Test]
    public function aRecordSurvivesTheProcessThatWroteIt(): void
    {
        // The whole point of the file store: an evidence chain links each record
        // to its predecessor's signature, and predecessors held in memory are gone
        // before the successor is written.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';

        new FileEvidenceStore($path)->store($this->record('a1'));

        $reopened = new FileEvidenceStore($path);

        self::assertCount(1, $reopened->all());
        self::assertSame('a1', $reopened->all()[0]->id);
        self::assertSame('sig-a1', $reopened->all()[0]->signature);
    }

    #[Test]
    public function roundTripsEveryFieldOfARecord(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);
        $collectedAt = new DateTimeImmutable('2026-02-03T04:05:06+00:00');

        $store->store(new EvidenceRecord(
            id: 'full',
            controlId: EvidenceChain::CONTROL_ID,
            type: 'verification_evidence',
            description: 'Compliance verification: 3/4 checks passed',
            data: ['pass_count' => 3, 'fail_count' => 1, 'previous_signature' => 'prev'],
            collectedAt: $collectedAt,
            signature: 'sig-full',
        ));

        $read = new FileEvidenceStore($path)->get('full');

        self::assertNotNull($read);
        self::assertSame(EvidenceChain::CONTROL_ID, $read->controlId);
        self::assertSame('verification_evidence', $read->type);
        self::assertSame('Compliance verification: 3/4 checks passed', $read->description);
        self::assertSame(['pass_count' => 3, 'fail_count' => 1, 'previous_signature' => 'prev'], $read->data);
        self::assertSame($collectedAt->getTimestamp(), $read->collectedAt->getTimestamp());
        self::assertSame('sig-full', $read->signature);
    }

    #[Test]
    public function keepsInsertionOrderSoTheChainCanBeWalkedForward(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);

        $store->store($this->record('first'));
        $store->store($this->record('second'));
        $store->store($this->record('third'));

        $ids = [];

        foreach ($store->forControl(EvidenceChain::CONTROL_ID) as $record) {
            $ids[] = $record->id;
        }

        self::assertSame(['first', 'second', 'third'], $ids);
        self::assertSame('third', $store->latestForControl(EvidenceChain::CONTROL_ID)?->id);
    }

    #[Test]
    public function filtersByControlAndCounts(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);

        $store->store($this->record('v1'));
        $store->store($this->record('other', controlId: 'something.else'));
        $store->store($this->record('v2'));

        self::assertSame(2, $store->countForControl(EvidenceChain::CONTROL_ID));
        self::assertSame(1, $store->countForControl('something.else'));
        self::assertNull($store->latestForControl('never.written'));
    }

    #[Test]
    public function aTruncatedTrailingLineDoesNotMakeTheRegisterUnreadable(): void
    {
        // A crash mid-append must not cost the operator every earlier record.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);
        $store->store($this->record('intact'));

        file_put_contents($path, '{"id":"broken","control', FILE_APPEND);

        self::assertCount(1, $store->all());
        self::assertSame('intact', $store->all()[0]->id);
    }

    #[Test]
    public function theSkippedLineIsStillReportedToWhoeverIsAboutToAppend(): void
    {
        // Skipping is right for reading and wrong for resuming: a chain that
        // resumed from the last DECODABLE record would append a successor that
        // closes over the truncation, and the missing record would be gone
        // without a trace. all() stays quiet; chainState() does not.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);
        $store->store($this->record('intact'));

        self::assertSame(AuditChainState::Healthy, $store->chainState());

        file_put_contents($path, '{"id":"broken","control', FILE_APPEND);

        self::assertSame(AuditChainState::Corrupted, $store->chainState());
    }

    #[Test]
    public function anAbsentOrBlankRegisterIsEmptyRatherThanCorrupt(): void
    {
        // The distinction the audit sink draws for the same reason: a fresh
        // deployment must be able to write its first record, and only a
        // non-empty-but-unreadable store may block one.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';

        self::assertSame(AuditChainState::Empty, new FileEvidenceStore($path)->chainState());

        file_put_contents($path, "\n");

        self::assertSame(AuditChainState::Empty, new FileEvidenceStore($path)->chainState());
    }

    #[Test]
    public function createsTheDirectoryItWritesInto(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'evidence.jsonl';

        new FileEvidenceStore($path)->store($this->record('made-dir'));

        self::assertTrue(is_file($path));
    }

    #[Test]
    public function refusesSilenceWhenTheRegisterCannotBeWritten(): void
    {
        // A store that swallowed the failure would leave the deployment believing
        // it holds an evidence trail it does not hold.
        $blocked = $this->dir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocked, 'not a directory');

        $this->expectException(EvidenceWriteFailedException::class);

        new FileEvidenceStore($blocked . DIRECTORY_SEPARATOR . 'evidence.jsonl')
            ->store($this->record('nope'));
    }

    #[Test]
    public function anAbsentRegisterIsEmptyRatherThanAnError(): void
    {
        $store = new FileEvidenceStore($this->dir . DIRECTORY_SEPARATOR . 'never-written.jsonl');

        self::assertSame([], $store->all());
        self::assertNull($store->get('anything'));
        self::assertSame(0, $store->countForControl(EvidenceChain::CONTROL_ID));
    }

    #[Test]
    public function recentReturnsTheTailOldestFirst(): void
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);

        foreach (['r1', 'r2', 'r3', 'r4'] as $id) {
            $store->store($this->record($id));
        }

        $ids = [];

        foreach ($store->recent(2) as $record) {
            $ids[] = $record->id;
        }

        self::assertSame(['r3', 'r4'], $ids);
        self::assertCount(4, $store->recent(0));
    }

    #[Test]
    public function theAnchorSitsBesideTheRegisterAndNotInsideIt(): void
    {
        // Inside would defeat it: an anchor appended as the register's last line
        // is removed by the same cut that removes the records it attests.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);

        self::assertSame($path . '.head', $store->headPath());
        self::assertFalse($store->hasHead());
        self::assertNull($store->head());

        $store->writeHead($this->head(height: 4));

        self::assertTrue($store->hasHead());
        self::assertFileExists($store->headPath());

        $reopened = new FileEvidenceStore($path)->head();

        self::assertNotNull($reopened);
        self::assertSame(4, $reopened->height);
        self::assertSame('tail-signature', $reopened->signature);
        self::assertSame('mac', $reopened->mac);
    }

    #[Test]
    public function theAnchorIsOverwrittenRatherThanAppendedTo(): void
    {
        // A file of successive heights would need its own tail to be trusted,
        // which is the problem the anchor exists to solve.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);

        $store->writeHead($this->head(height: 1));
        $store->writeHead($this->head(height: 2));

        self::assertSame(2, $store->head()?->height);
        self::assertSame(
            1,
            substr_count(trim((string) file_get_contents($store->headPath())), "\n") + 1,
            'the anchor file holds exactly one line',
        );
    }

    #[Test]
    public function anAnchorThatCannotBeDecodedReadsAsAbsentRatherThanThrowing(): void
    {
        // The contract is "never throws": an anchor that is there and unreadable
        // is a finding the chain reports, not an exception the store raises into
        // whatever output the caller was producing.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);

        file_put_contents($store->headPath(), "{ this is not json\n");

        self::assertTrue($store->hasHead(), 'the anchor is present');
        self::assertNull($store->head(), 'and could not be read');

        file_put_contents($store->headPath(), "[1,2,3]\n");

        self::assertNull($store->head(), 'a JSON value of the wrong shape reads as unreadable too');

        file_put_contents($store->headPath(), "{\"height\":\"four\"}\n");

        self::assertNull($store->head(), 'and so does one with the wrong field types');
    }

    #[Test]
    public function anUnwritableAnchorIsReportedRatherThanSwallowed(): void
    {
        // A register whose anchor silently stopped being maintained keeps looking
        // verifiable while losing the only property that makes truncation visible.
        // A directory sitting where the anchor file goes is the portable way to
        // make one path unwritable while its register stays writable.
        $path = $this->dir . DIRECTORY_SEPARATOR . 'evidence.jsonl';
        $store = new FileEvidenceStore($path);
        mkdir($store->headPath(), 0o755, true);

        $this->expectException(EvidenceWriteFailedException::class);
        $this->expectExceptionMessageMatches('/anchor/');

        $store->writeHead($this->head(height: 1));
    }

    private function head(int $height): EvidenceChainHead
    {
        return new EvidenceChainHead(
            version: 1,
            genesis: 'genesis-commitment',
            height: $height,
            signature: 'tail-signature',
            updatedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            mac: 'mac',
        );
    }

    private function record(string $id, string $controlId = EvidenceChain::CONTROL_ID): EvidenceRecord
    {
        return new EvidenceRecord(
            id: $id,
            controlId: $controlId,
            type: 'verification_evidence',
            description: 'record ' . $id,
            data: ['previous_signature' => 'genesis'],
            collectedAt: new DateTimeImmutable(),
            signature: 'sig-' . $id,
        );
    }

    private function cleanDir(string $dir): void
    {
        $items = scandir($dir);

        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $dir . DIRECTORY_SEPARATOR . $item;
                is_dir($path) ? $this->cleanDir($path) : unlink($path);
            }
        }

        rmdir($dir);
    }
}
