<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AI\Audit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\AI\Audit\EgressDecision;
use Pulsar\AI\Audit\Internal\PendingEgressDecision;

/**
 * The seam through which an egress control tells the auditor what it decided.
 *
 * Two properties, and both are about a record that must not overstate itself: a
 * decision may not claim a redaction it did not perform or a refusal it cannot
 * explain, and a decision belongs to exactly one call — reading it clears it, so
 * the NEXT inference, which nothing looked at, cannot inherit a clean verdict.
 */
#[CoversClass(EgressDecision::class)]
#[CoversClass(PendingEgressDecision::class)]
final class EgressDecisionTest extends TestCase
{
    #[Test]
    public function aPassCarriesNoRedactionAndNoRefusal(): void
    {
        $decision = EgressDecision::passed();

        self::assertFalse($decision->redactionApplied);
        self::assertSame(0, $decision->redactedSpanCount);
        self::assertFalse($decision->refused);
        self::assertSame('', $decision->refusalReason);
        self::assertSame(0, $decision->categoryCount());
    }

    #[Test]
    public function aRedactionOfNothingIsRefusedBecauseItWouldOverstateTheControl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $impossible = EgressDecision::redacted(0, ['pii.email']);

        self::fail('a redaction of nothing must not be constructible, got ' . $impossible->redactedSpanCount);
    }

    #[Test]
    public function aRefusalNobodyCanExplainIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $impossible = EgressDecision::refusal('   ');

        self::fail('a refusal with no reason must not be constructible, got "' . $impossible->refusalReason . '"');
    }

    #[Test]
    public function aRedactionCarriesItsSpanCountAndCategories(): void
    {
        $decision = EgressDecision::redacted(4, ['pii.email', 'phi']);

        self::assertTrue($decision->redactionApplied);
        self::assertSame(4, $decision->redactedSpanCount);
        self::assertSame(['pii.email', 'phi'], $decision->categories);
        self::assertFalse($decision->refused);
    }

    #[Test]
    public function takingADecisionClearsTheSlotSoTheNextCallCannotInheritIt(): void
    {
        $pending = new PendingEgressDecision();

        self::assertNull($pending->takeEgressDecision());

        $pending->report(EgressDecision::redacted(1, ['pci.pan']));

        self::assertNotNull($pending->takeEgressDecision());
        self::assertNull(
            $pending->takeEgressDecision(),
            'a decision that survived its own call would be attributed to an inference nothing examined',
        );
    }

    #[Test]
    public function aSecondReportForTheSameCallReplacesTheFirst(): void
    {
        $pending = new PendingEgressDecision();

        $pending->report(EgressDecision::redacted(1, ['pii.email']));
        $pending->report(EgressDecision::refusal('pan_detected'));

        $taken = $pending->takeEgressDecision();

        self::assertNotNull($taken);
        self::assertTrue($taken->refused);
    }
}
