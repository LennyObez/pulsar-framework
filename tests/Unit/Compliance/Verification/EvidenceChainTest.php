<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Evidence\EvidenceChainHead;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\EvidenceChainVerdict;
use Pulsar\Compliance\Verification\UnverifiableEvidenceChainException;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Tests\Support\Compliance\MutableEvidenceRegister;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;
use function str_repeat;
use function strlen;

#[CoversClass(EvidenceChain::class)]
#[CoversClass(EvidenceChainVerdict::class)]
#[CoversClass(EvidenceChainHead::class)]
final class EvidenceChainTest extends TestCase
{
    private const string TEST_KEY = 'test-evidence-key-that-is-long-enough-for-blake2b';

    private MutableEvidenceRegister $store;

    protected function setUp(): void
    {
        $this->store = new MutableEvidenceRegister();
    }

    private function createChain(?Randomizer $randomizer = null): EvidenceChain
    {
        return new EvidenceChain(
            $this->store,
            self::TEST_KEY,
            $randomizer ?? new Randomizer(new Mt19937(42)),
        );
    }

    /**
     * A fresh chain over the register as it now stands — the verifier an auditor
     * runs, which has no memory of the process that wrote the records.
     */
    private function reader(string $key = self::TEST_KEY): EvidenceChain
    {
        return new EvidenceChain($this->store, $key, new Randomizer(new Mt19937(7)));
    }

    /**
     * Three genuine records, written by the real writer.
     *
     * @return list<EvidenceRecord>
     */
    private function seed(): array
    {
        $chain = $this->createChain();

        return [
            $chain->record($this->createReport(passes: 1)),
            // Neither of the two records the alteration cases operate on may sit
            // at a 100% pass rate, or rewriting `pass_rate` to 100.0 alters
            // nothing and the case proves nothing.
            $chain->record($this->createReport(passes: 2, fails: 1)),
            $chain->record($this->createReport(passes: 3, fails: 1)),
        ];
    }

    /**
     * A copy of a record with one field replaced and everything else — including
     * the signature — carried across untouched. The attacker's tool: they cannot
     * compute a signature, so they keep the one they have.
     *
     * @param array<string, mixed>|null $data
     */
    private static function rebuild(
        EvidenceRecord $record,
        ?string $id = null,
        ?string $controlId = null,
        ?string $type = null,
        ?string $description = null,
        ?array $data = null,
        ?DateTimeImmutable $collectedAt = null,
        ?string $signature = null,
    ): EvidenceRecord {
        return new EvidenceRecord(
            id: $id ?? $record->id,
            controlId: $controlId ?? $record->controlId,
            type: $type ?? $record->type,
            description: $description ?? $record->description,
            data: $data ?? $record->data,
            collectedAt: $collectedAt ?? $record->collectedAt,
            signature: $signature ?? $record->signature,
        );
    }

    private static function alterData(
        EvidenceRecord $record,
        string $key,
        string|int|float $value,
    ): EvidenceRecord {
        $data = $record->data;
        $data[$key] = $value;

        return self::rebuild($record, data: $data);
    }

    private function createReport(int $passes = 3, int $fails = 0, int $skips = 0): VerificationReport
    {
        $results = [];

        for ($i = 0; $i < $passes; $i++) {
            $results[] = CheckResult::pass("check.pass.{$i}", 'Passed', ComplianceCheckDomain::Encryption);
        }

        for ($i = 0; $i < $fails; $i++) {
            $results[] = CheckResult::fail("check.fail.{$i}", 'Failed', ComplianceCheckDomain::Authentication);
        }

        for ($i = 0; $i < $skips; $i++) {
            $results[] = CheckResult::skip("check.skip.{$i}", 'Skipped', ComplianceCheckDomain::AuditLogging);
        }

        return new VerificationReport(
            frameworks: [ComplianceFramework::Gdpr],
            results: $results,
        );
    }

    #[Test]
    public function recordStoresEvidenceAndReturnsSigned(): void
    {
        $chain = $this->createChain();
        $report = $this->createReport(passes: 5, fails: 1);

        $record = $chain->record($report);

        self::assertNotEmpty($record->id);
        self::assertSame('compliance.verification_run', $record->controlId);
        self::assertSame('verification_evidence', $record->type);
        self::assertNotNull($record->signature);
        self::assertStringContainsString('5/6 checks passed', $record->description);
    }

    #[Test]
    public function recordIsPersistedToStore(): void
    {
        $chain = $this->createChain();

        $chain->record($this->createReport());

        self::assertCount(1, $this->store->all());
    }

    #[Test]
    public function recordDataContainsExpectedCounts(): void
    {
        $chain = $this->createChain();
        $report = $this->createReport(passes: 4, fails: 2, skips: 1);

        $record = $chain->record($report);

        self::assertSame(4, $record->data['pass_count']);
        self::assertSame(2, $record->data['fail_count']);
        self::assertSame(1, $record->data['skip_count']);
        self::assertArrayHasKey('pass_rate', $record->data);
        self::assertArrayHasKey('previous_signature', $record->data);
    }

    #[Test]
    public function everyRecordCarriesItsPositionInTheChain(): void
    {
        $records = $this->seed();

        self::assertSame(0, $records[0]->data['sequence']);
        self::assertSame(1, $records[1]->data['sequence']);
        self::assertSame(2, $records[2]->data['sequence']);
    }

    #[Test]
    public function appendingWritesAnAnchorAttestingTheHeight(): void
    {
        $records = $this->seed();

        $head = $this->store->head;

        self::assertNotNull($head);
        self::assertSame(3, $head->height);
        self::assertSame($records[2]->signature, $head->signature);
    }

    #[Test]
    public function chainSignatureUpdatesAfterRecord(): void
    {
        $chain = $this->createChain();
        $initial = $chain->currentSignature();

        $chain->record($this->createReport());
        $afterFirst = $chain->currentSignature();

        $chain->record($this->createReport(passes: 1, fails: 1));
        $afterSecond = $chain->currentSignature();

        self::assertNotSame($initial, $afterFirst);
        self::assertNotSame($afterFirst, $afterSecond);
    }

    #[Test]
    public function heightIsTheNumberOfRecordsWritten(): void
    {
        $chain = $this->createChain();

        self::assertSame(0, $chain->height());

        $chain->record($this->createReport());
        $chain->record($this->createReport());

        self::assertSame(2, $chain->height());
        self::assertSame(2, $this->reader()->height(), 'a fresh process resumes the same height');
    }

    // ---------------------------------------------------------------- intact

    #[Test]
    public function anUntouchedRegisterIsIntact(): void
    {
        $this->seed();

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Intact, $result->verdict);
        self::assertTrue($result->admissible());
        self::assertSame(3, $result->present);
        self::assertSame(3, $result->attested);
        self::assertSame(3, $result->verified);
        self::assertSame(3, $result->examined);
        self::assertSame([], $result->brokenAt);
    }

    #[Test]
    public function aBoundedRunStatesHowMuchItRecomputed(): void
    {
        $this->seed();

        $result = $this->reader()->verify(limit: 1);

        self::assertSame(EvidenceChainVerdict::Intact, $result->verdict);
        self::assertSame(3, $result->present, 'the whole register is still counted');
        self::assertSame(1, $result->examined, 'only the tail had its signature recomputed');
        self::assertSame(1, $result->verified);
    }

    #[Test]
    public function aBoundedRunAnchorsItsWindowToTheRealPredecessor(): void
    {
        // The window's first record does not chain to genesis. The verifier reads
        // the record before it from the store rather than taking an anchor from a
        // caller, which is what used to produce a false tamper report on every
        // deployment whose register outgrew the limit.
        $this->seed();

        self::assertSame(EvidenceChainVerdict::Intact, $this->reader()->verify(limit: 2)->verdict);
    }

    // ------------------------------------------------------------- truncated

    #[Test]
    public function droppingTheLastRecordIsTruncation(): void
    {
        $this->seed();
        $this->store->keepFirst(2);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(2, $result->present);
        self::assertSame(3, $result->attested);
        self::assertSame(['2'], $result->brokenAt);
    }

    #[Test]
    public function droppingEverythingButTheFirstRecordIsTruncation(): void
    {
        $this->seed();
        $this->store->keepFirst(1);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(['1', '2'], $result->brokenAt);
    }

    #[Test]
    public function droppingARecordFromTheMiddleIsTruncation(): void
    {
        $this->seed();
        $this->store->remove(1);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(['1'], $result->brokenAt);
    }

    #[Test]
    public function emptyingTheRegisterWhileTheAnchorSurvivesIsTruncation(): void
    {
        $this->seed();
        $this->store->records = [];

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(0, $result->present);
        self::assertSame(3, $result->attested);
        self::assertSame(['0', '1', '2'], $result->brokenAt);
    }

    // ------------------------------------------------------------- reordered

    #[Test]
    public function swappingTwoRecordsIsReordering(): void
    {
        $this->seed();
        $this->store->swap(0, 1);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Reordered, $result->verdict);
        self::assertSame(3, $result->present);
    }

    #[Test]
    public function aRepeatedPositionIsReordering(): void
    {
        $this->seed();
        $this->store->duplicate(2);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Reordered, $result->verdict);
        self::assertSame(4, $result->present);
    }

    // -------------------------------------------------------------- modified

    /**
     * Every field an auditor reads a verdict off, altered one at a time.
     *
     * Each case is a record differing from a genuine one in exactly one field and
     * carrying the genuine signature. `control_id` is in the list on purpose: it
     * is the field the reader used to SELECT on, so altering it made a record
     * invisible to the verifier rather than visible to it.
     *
     * @return iterable<string, array{callable(EvidenceRecord): EvidenceRecord}>
     */
    public static function alteredFieldProvider(): iterable
    {
        yield 'id' => [static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild($r, id: 'forged-id')];

        yield 'control_id' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild($r, controlId: 'some.other.control'),
        ];

        yield 'type' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild($r, type: 'manual_evidence'),
        ];

        yield 'description' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild(
                $r,
                description: 'Compliance verification: 99/99 checks passed (100.0% pass rate)',
            ),
        ];

        yield 'collected_at' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild(
                $r,
                collectedAt: $r->collectedAt->modify('-30 days'),
            ),
        ];

        yield 'signature' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild(
                $r,
                signature: str_repeat('a', 64),
            ),
        ];

        // Assigned in place rather than merged, so the key ORDER is untouched and
        // each case is a single altered value and nothing else.
        yield 'data.pass_count' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::alterData($r, 'pass_count', 999),
        ];

        yield 'data.fail_count' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::alterData($r, 'fail_count', 7),
        ];

        yield 'data.pass_rate' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::alterData($r, 'pass_rate', 100.0),
        ];

        yield 'data.previous_signature' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::alterData(
                $r,
                'previous_signature',
                str_repeat('b', 64),
            ),
        ];

        yield 'data.chain_genesis' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::alterData(
                $r,
                'chain_genesis',
                str_repeat('c', 64),
            ),
        ];

        yield 'data.signature_version' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::alterData($r, 'signature_version', 2),
        ];

        yield 'data key removed' => [
            static function (EvidenceRecord $r): EvidenceRecord {
                $data = $r->data;
                unset($data['fail_count']);

                return self::rebuild($r, data: $data);
            },
        ];

        yield 'data key added' => [
            static fn(EvidenceRecord $r): EvidenceRecord => self::rebuild(
                $r,
                data: $r->data + ['auditor_note' => 'all clear'],
            ),
        ];
    }

    /**
     * @param callable(EvidenceRecord): EvidenceRecord $alter
     */
    #[Test]
    #[DataProvider('alteredFieldProvider')]
    public function alteringAnySignedFieldOfTheTailIsAModification(callable $alter): void
    {
        $records = $this->seed();

        self::assertSame(EvidenceChainVerdict::Intact, $this->reader()->verify()->verdict);

        $altered = $alter($records[2]);
        $this->store->replace(2, $altered);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Modified, $result->verdict);
        self::assertFalse($result->admissible());
        self::assertSame([$altered->id], $result->brokenAt);
        self::assertSame(2, $result->verified, 'the untouched records still hold');
    }

    /**
     * @param callable(EvidenceRecord): EvidenceRecord $alter
     */
    #[Test]
    #[DataProvider('alteredFieldProvider')]
    public function alteringAnySignedFieldOfAMiddleRecordIsAModification(callable $alter): void
    {
        $records = $this->seed();

        $altered = $alter($records[1]);
        $this->store->replace(1, $altered);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Modified, $result->verdict);
        self::assertContains($altered->id, $result->brokenAt);
    }

    #[Test]
    public function alteringThePositionRemovesTheRecordFromThePositionItClaimed(): void
    {
        // sequence is inside the signature, so rewriting it cannot be hidden. What
        // it produces is a register with no record at the position the anchor
        // attests, which is the true statement about the file.
        $records = $this->seed();
        $this->store->replace(2, self::alterData($records[2], 'sequence', 99));

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Truncated, $result->verdict);
        self::assertSame(['2'], $result->brokenAt);
    }

    #[Test]
    public function anAnchorNamingADifferentRecordAtTheTailIsAModification(): void
    {
        // Two writers resumed from the same tail and each appended at position 1;
        // one of the two was then removed. Both records are genuine, so
        // recomputing signatures finds nothing wrong — the anchor is what notices
        // that the register's tail is not the tail the chain committed to.
        $this->createChain()->record($this->createReport(passes: 1));

        $first = $this->reader();
        $second = $this->reader();

        // Both resume before either appends, which is what makes them a race: each
        // now believes position 1 is its to write.
        self::assertSame(1, $first->height());
        self::assertSame(1, $second->height());

        $byFirst = $first->record($this->createReport(passes: 2));
        $second->record($this->createReport(passes: 3));

        // The anchor now names the second writer's record. Remove it and leave the
        // first writer's, which sits at the same position and is equally genuine.
        $this->store->remove(2);

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Modified, $result->verdict);
        self::assertSame([$byFirst->id], $result->brokenAt);
        self::assertStringContainsString('anchor names a different record', $result->summary);
    }

    // ------------------------------------------------------------ unreadable

    #[Test]
    public function aStoreReportingALineItCouldNotReadIsUnreadable(): void
    {
        $this->seed();
        $this->store->unreadable = true;

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Unreadable, $result->verdict);
        self::assertStringContainsString('could not read', $result->summary);
    }

    #[Test]
    public function theReportNoLongerCertifiesARegisterTheStoreCallsCorrupted(): void
    {
        // The defect this replaced: resume() consulted chainState() and refused,
        // while verifyChain() never consulted it and reported valid=YES over the
        // lines that happened to decode. One file, two answers.
        $this->seed();
        $this->store->unreadable = true;

        $reader = $this->reader();

        self::assertFalse($reader->verify()->admissible());
        self::assertNotNull($reader->appendRefusal());
    }

    #[Test]
    public function aRegisterWithNoAnchorIsUnreadable(): void
    {
        $this->seed();
        $this->store->head = null;

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Unreadable, $result->verdict);
        self::assertStringContainsString('no anchor', $result->summary);
    }

    #[Test]
    public function anAnchorRewrittenToAnyHeightFailsItsMac(): void
    {
        $this->seed();
        $head = $this->store->head;
        self::assertNotNull($head);

        $this->store->head = new EvidenceChainHead(
            version: $head->version,
            genesis: $head->genesis,
            height: 99,
            signature: $head->signature,
            updatedAt: $head->updatedAt,
            mac: $head->mac,
        );

        self::assertSame(EvidenceChainVerdict::Unreadable, $this->reader()->verify()->verdict);
    }

    #[Test]
    public function anAnchorInAnUnknownLayoutIsRefusedRatherThanGuessedAt(): void
    {
        $this->seed();
        $head = $this->store->head;
        self::assertNotNull($head);

        $this->store->head = new EvidenceChainHead(
            version: 99,
            genesis: $head->genesis,
            height: $head->height,
            signature: $head->signature,
            updatedAt: $head->updatedAt,
            mac: $head->mac,
        );

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Unreadable, $result->verdict);
        self::assertStringContainsString('layout version', $result->summary);
    }

    // ----------------------------------------------------------------- empty

    #[Test]
    public function anEmptyRegisterIsNotAVerifiedOne(): void
    {
        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Empty, $result->verdict);
        self::assertFalse($result->admissible(), 'zero records verified is not a proof');
        self::assertSame(0, $result->present);
    }

    #[Test]
    public function anEmptyRegisterStillAcceptsTheFirstAppend(): void
    {
        self::assertNull($this->reader()->appendRefusal());
    }

    // ------------------------------------------------------------ unanchored

    #[Test]
    public function aStoreThatCannotAttestItsHeightIsReportedUnanchored(): void
    {
        $store = new class implements EvidenceStoreInterface {
            /** @var list<EvidenceRecord> */
            public array $records = [];

            public function store(EvidenceRecord $record): void
            {
                $this->records[] = $record;
            }

            /** @return list<EvidenceRecord> */
            public function forControl(string $controlId): array
            {
                return $this->records;
            }

            /** @return list<EvidenceRecord> */
            public function all(): array
            {
                return $this->records;
            }

            public function get(string $id): ?EvidenceRecord
            {
                return null;
            }

            public function countForControl(string $controlId): int
            {
                return count($this->records);
            }
        };

        $chain = new EvidenceChain($store, self::TEST_KEY, new Randomizer(new Mt19937(42)));
        $chain->record($this->createReport());
        $chain->record($this->createReport(passes: 2));

        $result = new EvidenceChain($store, self::TEST_KEY, new Randomizer(new Mt19937(1)))->verify();

        self::assertSame(EvidenceChainVerdict::Unanchored, $result->verdict);
        self::assertFalse($result->admissible(), 'completeness was not checked, so it is not evidence');
        self::assertNull($result->attested);
        self::assertSame(2, $result->verified, 'the records present were still recomputed');
    }

    #[Test]
    public function anUnanchoredRegisterStillAcceptsAppends(): void
    {
        // The verdict is a stated weakness, not a fault in the register. Refusing
        // to append would take a deployment's evidence collection offline over a
        // guarantee its store never offered.
        $store = new class implements EvidenceStoreInterface {
            /** @var list<EvidenceRecord> */
            public array $records = [];

            public function store(EvidenceRecord $record): void
            {
                $this->records[] = $record;
            }

            /** @return list<EvidenceRecord> */
            public function forControl(string $controlId): array
            {
                return $this->records;
            }

            /** @return list<EvidenceRecord> */
            public function all(): array
            {
                return $this->records;
            }

            public function get(string $id): ?EvidenceRecord
            {
                return null;
            }

            public function countForControl(string $controlId): int
            {
                return count($this->records);
            }
        };

        new EvidenceChain($store, self::TEST_KEY, new Randomizer(new Mt19937(42)))
            ->record($this->createReport());

        $second = new EvidenceChain($store, self::TEST_KEY, new Randomizer(new Mt19937(9)));

        self::assertNull($second->appendRefusal());
        $second->record($this->createReport(passes: 2));

        self::assertCount(2, $store->all());
    }

    // ------------------------------------------------------- key unavailable

    #[Test]
    public function aRegisterSignedUnderAnotherKeyIsReportedAsSuchAndNotAsTampering(): void
    {
        $this->seed();

        $result = $this->reader('a-completely-different-evidence-key-of-adequate-length')->verify();

        self::assertSame(EvidenceChainVerdict::KeyUnavailable, $result->verdict);
        self::assertStringContainsString('key rotation', $result->summary);
    }

    #[Test]
    public function aChainRefusesToAppendToARegisterItCannotVerify(): void
    {
        $this->seed();
        $this->store->keepFirst(2);

        $chain = $this->reader();

        self::assertNotNull($chain->appendRefusal());

        $this->expectException(UnverifiableEvidenceChainException::class);
        $chain->record($this->createReport());
    }

    // ----------------------------------------------------- crash and healing

    #[Test]
    public function aRegisterOneRecordAheadOfItsAnchorIsIntactAndReAnchors(): void
    {
        // What a crash between the record write and the anchor write leaves. Only
        // the key holder can have produced that record, so it is this chain's.
        // The anchor here is the genuine one the writer produced after the second
        // record — not a hand-edited one, which is a different finding.
        $chain = $this->createChain();
        $chain->record($this->createReport(passes: 1));
        $chain->record($this->createReport(passes: 2));
        $anchorAfterTwo = $this->store->head;
        self::assertNotNull($anchorAfterTwo);

        $chain->record($this->createReport(passes: 3));
        $this->store->head = $anchorAfterTwo;

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Intact, $result->verdict);
        self::assertSame(3, $result->present);
        self::assertSame(2, $result->attested);
        self::assertStringContainsString('ahead of its anchor', $result->summary);

        // Resuming heals the anchor rather than waiting for the next append.
        $reader = $this->reader();
        self::assertNull($reader->appendRefusal());
        self::assertSame(3, $this->store->head?->height);
    }

    // --------------------------------------------------------- period summary

    #[Test]
    public function periodSummaryReturnsZeroForEmptyRecords(): void
    {
        $result = $this->createChain()->periodSummary([]);

        self::assertSame('', $result['from']);
        self::assertSame('', $result['to']);
        self::assertSame(0, $result['run_count']);
        self::assertSame(0.0, $result['average_pass_rate']);
    }

    #[Test]
    public function periodSummaryCalculatesAveragePassRate(): void
    {
        $chain = $this->createChain();

        $r1 = $chain->record($this->createReport(passes: 4, fails: 0));
        $r2 = $chain->record($this->createReport(passes: 2, fails: 2));

        $summary = $chain->periodSummary([$r1, $r2]);

        self::assertSame(2, $summary['run_count']);
        self::assertSame(75.0, $summary['average_pass_rate']);
        self::assertNotSame('', $summary['from']);
        self::assertNotSame('', $summary['to']);
    }

    #[Test]
    public function periodSummaryFiltersNonVerificationRecords(): void
    {
        $chain = $this->createChain();

        $verification = $chain->record($this->createReport(passes: 3));

        $nonVerification = new EvidenceRecord(
            id: 'other-1',
            controlId: 'something_else',
            type: 'manual_evidence',
            description: 'Manual check',
            data: ['pass_rate' => 999.0],
            collectedAt: new DateTimeImmutable(),
        );

        $summary = $chain->periodSummary([$verification, $nonVerification]);

        self::assertSame(1, $summary['run_count']);
    }

    // ---------------------------------------------------------------- basics

    #[Test]
    public function currentSignatureIsInitializedOnConstruction(): void
    {
        $sig = $this->createChain()->currentSignature();

        self::assertNotEmpty($sig);
        self::assertSame(64, strlen($sig));
    }

    #[Test]
    public function consecutiveRecordsChainsSignatures(): void
    {
        $chain = $this->createChain();

        $r1 = $chain->record($this->createReport());
        $r2 = $chain->record($this->createReport());

        self::assertSame($r1->signature, $r2->data['previous_signature']);
    }

    #[Test]
    public function determinismWithSameKey(): void
    {
        $chain1 = $this->createChain(new Randomizer(new Mt19937(100)));
        $chain2 = new EvidenceChain(
            new MutableEvidenceRegister(),
            self::TEST_KEY,
            new Randomizer(new Mt19937(100)),
        );

        self::assertSame($chain1->currentSignature(), $chain2->currentSignature());
    }

    #[Test]
    public function recordWithAllPassesProducesFullPassDescription(): void
    {
        $record = $this->createChain()->record($this->createReport(passes: 10, fails: 0));

        self::assertStringContainsString('10/10 checks passed', $record->description);
        self::assertStringContainsString('100', $record->description);
    }

    #[Test]
    public function recordWithAllFailsProducesZeroPassDescription(): void
    {
        $record = $this->createChain()->record($this->createReport(passes: 0, fails: 5));

        self::assertStringContainsString('0/5 checks passed', $record->description);
    }

    #[Test]
    public function anApplicationsOwnEvidenceIsNotPartOfThisChain(): void
    {
        $this->seed();

        $this->store->store(new EvidenceRecord(
            id: 'app-1',
            controlId: 'app.some.control',
            type: 'configuration',
            description: 'An application record in the same store',
            data: ['whatever' => true],
            collectedAt: new DateTimeImmutable(),
        ));

        $result = $this->reader()->verify();

        self::assertSame(EvidenceChainVerdict::Intact, $result->verdict);
        self::assertSame(3, $result->present, 'the foreign record is not counted as a chain member');
    }
}
