<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Evidence\EvidenceStoreInterface;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\VerificationReport;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function count;
use function strlen;

#[CoversClass(EvidenceChain::class)]
final class EvidenceChainTest extends TestCase
{
    private const string TEST_KEY = 'test-evidence-key-that-is-long-enough-for-blake2b';

    private EvidenceStoreInterface $store;

    protected function setUp(): void
    {
        $this->store = new class implements EvidenceStoreInterface {
            /** @var list<EvidenceRecord> */
            public array $records = [];

            public function store(EvidenceRecord $record): void
            {
                $this->records[] = $record;
            }

            /** @return list<EvidenceRecord> */
            public function forControl(string $controlId): array
            {
                $result = [];
                foreach ($this->records as $r) {
                    if ($r->controlId === $controlId) {
                        $result[] = $r;
                    }
                }
                return $result;
            }

            /** @return list<EvidenceRecord> */
            public function all(): array
            {
                return $this->records;
            }

            public function get(string $id): ?EvidenceRecord
            {
                foreach ($this->records as $r) {
                    if ($r->id === $id) {
                        return $r;
                    }
                }
                return null;
            }

            public function countForControl(string $controlId): int
            {
                return count($this->forControl($controlId));
            }
        };
    }

    private function createChain(?Randomizer $randomizer = null): EvidenceChain
    {
        return new EvidenceChain(
            $this->store,
            self::TEST_KEY,
            $randomizer ?? new Randomizer(new Mt19937(42)),
        );
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
        $report = $this->createReport();

        $chain->record($report);

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
    public function verifyChainSucceedsForValidRecords(): void
    {
        $chain = $this->createChain();

        $r1 = $chain->record($this->createReport());
        $r2 = $chain->record($this->createReport(passes: 2, fails: 1));

        $result = $chain->verifyChain([$r1, $r2]);

        self::assertTrue($result['valid']);
        self::assertSame(2, $result['verified']);
        self::assertSame([], $result['broken_at']);
    }

    #[Test]
    public function verifyChainDetectsTamperedRecord(): void
    {
        $chain = $this->createChain();

        $r1 = $chain->record($this->createReport());

        // Tamper with the record by creating a new one with modified data
        $tampered = new EvidenceRecord(
            id: $r1->id,
            controlId: $r1->controlId,
            type: $r1->type,
            description: $r1->description,
            data: ['pass_count' => 999, 'fail_count' => 0, 'skip_count' => 0, 'pass_rate' => 100.0, 'previous_signature' => 'fake'],
            collectedAt: $r1->collectedAt,
            signature: $r1->signature,
        );

        $result = $chain->verifyChain([$tampered]);

        self::assertFalse($result['valid']);
        self::assertSame(0, $result['verified']);
        self::assertContains($r1->id, $result['broken_at']);
    }

    #[Test]
    public function verifyChainDetectsNullSignature(): void
    {
        $chain = $this->createChain();

        $unsigned = new EvidenceRecord(
            id: 'unsigned-1',
            controlId: 'test',
            type: 'verification_evidence',
            description: 'Unsigned record',
            data: [],
            collectedAt: new DateTimeImmutable(),
            signature: null,
        );

        $result = $chain->verifyChain([$unsigned]);

        self::assertFalse($result['valid']);
        self::assertSame(0, $result['verified']);
        self::assertContains('unsigned-1', $result['broken_at']);
    }

    #[Test]
    public function verifyChainHandlesEmptyArray(): void
    {
        $chain = $this->createChain();

        $result = $chain->verifyChain([]);

        self::assertTrue($result['valid']);
        self::assertSame(0, $result['verified']);
        self::assertSame([], $result['broken_at']);
    }

    #[Test]
    public function verifyChainMixesValidAndInvalid(): void
    {
        $chain = $this->createChain();

        $valid = $chain->record($this->createReport());

        $tampered = new EvidenceRecord(
            id: 'tampered-1',
            controlId: 'compliance.verification_run',
            type: 'verification_evidence',
            description: 'Tampered',
            data: ['pass_count' => 0, 'fail_count' => 0, 'skip_count' => 0, 'pass_rate' => 0.0, 'previous_signature' => 'bad'],
            collectedAt: new DateTimeImmutable(),
            signature: 'deadbeef',
        );

        $result = $chain->verifyChain([$valid, $tampered]);

        self::assertFalse($result['valid']);
        self::assertSame(1, $result['verified']);
        self::assertContains('tampered-1', $result['broken_at']);
        self::assertNotContains($valid->id, $result['broken_at']);
    }

    #[Test]
    public function periodSummaryReturnsZeroForEmptyRecords(): void
    {
        $chain = $this->createChain();

        $result = $chain->periodSummary([]);

        self::assertSame('', $result['from']);
        self::assertSame('', $result['to']);
        self::assertSame(0, $result['run_count']);
        self::assertSame(0.0, $result['average_pass_rate']);
    }

    #[Test]
    public function periodSummaryCalculatesAveragePassRate(): void
    {
        $chain = $this->createChain();

        // Record two reports: 100% and 50% pass rate
        $r1 = $chain->record($this->createReport(passes: 4, fails: 0));
        $r2 = $chain->record($this->createReport(passes: 2, fails: 2));

        $summary = $chain->periodSummary([$r1, $r2]);

        self::assertSame(2, $summary['run_count']);
        // (100.0 + 50.0) / 2 = 75.0
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

    #[Test]
    public function currentSignatureIsInitializedOnConstruction(): void
    {
        $chain = $this->createChain();
        $sig = $chain->currentSignature();

        self::assertNotEmpty($sig);
        // BLAKE2b hex output is 64 chars (32 bytes)
        self::assertSame(64, strlen($sig));
    }

    #[Test]
    public function consecutiveRecordsChainsSignatures(): void
    {
        $chain = $this->createChain();

        $r1 = $chain->record($this->createReport());
        $r2 = $chain->record($this->createReport());

        // r2's data should reference r1's signature as previous_signature
        self::assertSame($r1->signature, $r2->data['previous_signature']);
    }

    #[Test]
    public function determinismWithSameKey(): void
    {
        // Two chains with the same key should produce the same initial signature
        $chain1 = $this->createChain(new Randomizer(new Mt19937(100)));
        $chain2 = $this->createChain(new Randomizer(new Mt19937(100)));

        self::assertSame($chain1->currentSignature(), $chain2->currentSignature());
    }

    #[Test]
    public function recordWithAllPassesProducesFullPassDescription(): void
    {
        $chain = $this->createChain();
        $record = $chain->record($this->createReport(passes: 10, fails: 0));

        self::assertStringContainsString('10/10 checks passed', $record->description);
        self::assertStringContainsString('100', $record->description);
    }

    #[Test]
    public function recordWithAllFailsProducesZeroPassDescription(): void
    {
        $chain = $this->createChain();
        $record = $chain->record($this->createReport(passes: 0, fails: 5));

        self::assertStringContainsString('0/5 checks passed', $record->description);
    }
}
