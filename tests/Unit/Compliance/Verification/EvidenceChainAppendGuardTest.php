<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Evidence\EvidenceRecord;
use Pulsar\Compliance\Verification\CheckResult;
use Pulsar\Compliance\Verification\ComplianceCheckDomain;
use Pulsar\Compliance\Verification\ConcurrentEvidenceAppendException;
use Pulsar\Compliance\Verification\EvidenceChain;
use Pulsar\Compliance\Verification\VerificationReport;
use Pulsar\Tests\Support\Compliance\ReentrantEvidenceRegister;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_map;
use function array_unique;
use function array_values;
use function is_int;

/**
 * The evidence chain append admits one caller at a time, and says so.
 *
 * `EvidenceChain::record()` resumes the chain, signs a record carrying the
 * chain's current height as its position, stores it, publishes the new signature
 * and re-anchors. A second caller inside that window reads the same height, and
 * both records claim position N — which {@see EvidenceChain::verify()} reports as
 * a reordered register, correctly, and far too late for the register to be
 * repaired.
 *
 * What guarded the window was a spin on a bare `Fiber::suspend()`, entered only
 * when `Fiber::getCurrent() !== null` — so the nested-call case, the one that
 * actually happens, skipped the wait entirely, and the fiber case waited on
 * socket readability because that is what a valueless suspend means to
 * `FiberScheduler`. Both are now refused, from any context, before anything is
 * signed.
 *
 * @see \Pulsar\Compliance\Verification\ConcurrentEvidenceAppendException
 * @see \Pulsar\Tests\Unit\Concurrency\FiberSurfaceInventoryTest
 */
#[CoversClass(EvidenceChain::class)]
#[CoversClass(ConcurrentEvidenceAppendException::class)]
final class EvidenceChainAppendGuardTest extends TestCase
{
    private const string TEST_KEY = 'test-evidence-key-that-is-long-enough-for-blake2b';

    private ReentrantEvidenceRegister $store;

    protected function setUp(): void
    {
        $this->store = new ReentrantEvidenceRegister();
    }

    #[Test]
    public function aStoreThatRecordsIsRefusedRatherThanAdmitted(): void
    {
        $chain = $this->createReentrantChain();

        try {
            $chain->record($this->createReport());

            self::fail('the nested append was admitted to the chain');
        } catch (ConcurrentEvidenceAppendException $e) {
            self::assertStringContainsString('already appending at position 0', $e->getMessage());
        }
    }

    /**
     * The defect itself: without exclusion, two records claim position 0, and the
     * register is permanently reordered.
     */
    #[Test]
    public function twoRecordsCannotClaimTheSamePosition(): void
    {
        $chain = $this->createReentrantChain();

        try {
            $chain->record($this->createReport());
        } catch (ConcurrentEvidenceAppendException) {
            // The refusal is asserted by the test above; here only its effect matters.
        }

        $positions = array_map(
            static function (EvidenceRecord $record): string {
                $sequence = $record->data['sequence'] ?? null;

                return is_int($sequence) ? (string) $sequence : 'absent';
            },
            $this->store->records,
        );

        self::assertSame(
            $positions,
            array_values(array_unique($positions)),
            'two evidence records claim the same position: verification reports the whole '
            . 'register as reordered from here on',
        );
    }

    /**
     * The same refusal inside a fiber.
     *
     * `isTerminated()` is the assertion that matters. Against the previous
     * mechanism the fiber suspends inside the append and `start()` returns with it
     * still suspended, waiting on a condition — its connection socket becoming
     * readable — that has nothing to do with the chain being free, and that nothing
     * in this test or in the runtime will produce.
     */
    #[Test]
    public function aReentrantAppendInsideAFiberIsRefusedRatherThanParked(): void
    {
        $chain = $this->createReentrantChain();
        $report = $this->createReport();
        $refusal = null;

        $fiber = new Fiber(static function () use ($chain, $report, &$refusal): void {
            try {
                $chain->record($report);
            } catch (ConcurrentEvidenceAppendException $e) {
                $refusal = $e;
            }
        });

        $fiber->start();

        self::assertTrue(
            $fiber->isTerminated(),
            'the fiber suspended inside the append; nothing resumes a bare suspend here',
        );
        self::assertInstanceOf(ConcurrentEvidenceAppendException::class, $refusal);
    }

    /**
     * The flag is released on the way out, so a refusal does not wedge the chain.
     */
    #[Test]
    public function theChainKeepsAppendingAfterARefusal(): void
    {
        $chain = $this->createReentrantChain();

        try {
            $chain->record($this->createReport());
        } catch (ConcurrentEvidenceAppendException) {
            // Expected; the point is what happens next.
        }

        $this->store->chain = null;

        $record = $chain->record($this->createReport());

        self::assertSame(EvidenceChain::CONTROL_ID, $record->controlId);
    }

    /**
     * A chain over a store that re-enters it exactly once.
     */
    private function createReentrantChain(): EvidenceChain
    {
        $chain = new EvidenceChain(
            $this->store,
            self::TEST_KEY,
            new Randomizer(new Mt19937(42)),
        );

        $this->store->chain = $chain;
        $this->store->report = $this->createReport();

        return $chain;
    }

    private function createReport(): VerificationReport
    {
        return new VerificationReport(
            frameworks: [ComplianceFramework::Gdpr],
            results: [
                CheckResult::pass('check.pass.0', 'Passed', ComplianceCheckDomain::Encryption),
            ],
        );
    }
}
