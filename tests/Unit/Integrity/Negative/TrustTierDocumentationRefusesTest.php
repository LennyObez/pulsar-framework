<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity\Negative;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Tests\Support\Gates\GuardsGate;
use Pulsar\Tests\Unit\Integrity\Support\TrustTierTableScanner;

use function implode;

/**
 * The trust-tier documentation ratchet, watched refusing.
 *
 * `TrustTierDocumentationTest` reads three documents this repository keeps correct, so the
 * only answer it gives here is agreement — and agreement is also what a scan that failed to
 * find the tables would report. `GateNegativeCoverageTest` carried two of its rules as
 * PENDING exemptions for exactly that reason. This is what closes them: every rule handed
 * the drift it exists to catch, and watched refusing it.
 *
 * The drifts planted below are not invented. Each is a state one of these documents has
 * actually been in — `ContainerWrite` shown as granted below Core after the grant was
 * removed, capabilities with no row at all, and the config stub omitting `ContainerWrite`
 * from what `verified` is withheld. None was found by a test; each was found by a person
 * reading carefully, once, and the rest of the time nobody was reading.
 *
 * No filesystem fixture is needed here. The scan takes its text as an argument, which is
 * what lifting it out of the test class bought: a drifted table can be handed to the same
 * code that reads the real one.
 */
#[CoversClass(TrustTierTableScanner::class)]
#[GuardsGate(
    gate: 'TrustTierDocumentationTest::everyCapabilityRowMatchesThePolicy',
    plants: 'an ADR matrix row granting ContainerWrite to every tier, and a matrix with no row for a capability the policy grants',
)]
#[GuardsGate(
    gate: 'TrustTierDocumentationTest::theTableNamesNoCapabilityThatDoesNotExist',
    plants: 'an ADR matrix row for a capability name ExtensionCapability does not have',
)]
#[GuardsGate(
    gate: 'TrustTierDocumentationTest::theExtensionGuideSummaryMatchesThePolicy',
    plants: 'a tier summary granting Community the environment reader, and one calling Verified container access Full',
)]
#[GuardsGate(
    gate: 'TrustTierDocumentationTest::theConfigStubNamesExactlyWhatVerifiedIsWithheld',
    plants: 'the config docblock as it shipped, naming CryptoKeyAccess and ProcessExec and omitting ContainerWrite',
)]
final class TrustTierDocumentationRefusesTest extends TestCase
{
    /** The ADR matrix as it stands, trimmed to the rows these tests need. */
    private const string HEALTHY_MATRIX = <<<'MARKDOWN'
        | Capability            | Core | Verified | Community | Untrusted |
        | --------------------- | ---- | -------- | --------- | --------- |
        | `ContainerRead`       | yes  | yes      | yes       | yes       |
        | `ContainerWrite`      | yes  | no       | no        | no        |
        | `ProcessExec`         | yes  | no       | no        | no        |
        MARKDOWN;

    /** The tier summary as it stands, trimmed to two of its capability columns. */
    private const string HEALTHY_SUMMARY = <<<'MARKDOWN'
        | Tier      | Container | Routes   | `EnvRead` | `ProcessExec` |
        | --------- | --------- | -------- | --------- | ------------- |
        | Core      | Full      | Global   | yes       | yes           |
        | Verified  | Most      | Global   | yes       | no            |
        | Community | Limited   | Prefixed | no        | no            |
        | Untrusted | Read-only | No       | no        | no            |
        MARKDOWN;

    #[Test]
    public function itRefusesAMatrixCellThatOverstatesTheGrant(): void
    {
        // The defect: the row that was actually wrong once, in the direction that matters.
        // A reader concludes a Community extension may override a core binding.
        $drifted = <<<'MARKDOWN'
            | Capability            | Core | Verified | Community | Untrusted |
            | --------------------- | ---- | -------- | --------- | --------- |
            | `ContainerWrite`      | yes  | yes      | yes       | no        |
            MARKDOWN;

        $row = TrustTierTableScanner::matrixRow($drifted, ExtensionCapability::ContainerWrite);
        $granted = TrustTierTableScanner::grantedRow(
            CapabilityPolicy::defaults(),
            ExtensionCapability::ContainerWrite,
        );

        self::assertNotSame(
            $granted,
            $row,
            'The ratchet read a matrix granting ContainerWrite down to Community as agreeing with '
            . 'the policy, which withholds it below Core. What ships when it stays silent is an '
            . 'ADR telling a compliance reviewer that an unaudited third-party extension may '
            . 'override an existing binding — Session, Auth, CsrfGuard — when the sandbox refuses '
            . 'exactly that. Documentation overstating a control is worse than none, because it '
            . 'is believed. It read: [' . implode('/', (array) $row) . ']',
        );
    }

    #[Test]
    public function itRefusesAMatrixWithNoRowForACapability(): void
    {
        // The defect: ServiceRegister and ServiceDecorate were both absent from this table
        // for as long as they had existed.
        $row = TrustTierTableScanner::matrixRow(self::HEALTHY_MATRIX, ExtensionCapability::ServiceRegister);

        self::assertNull(
            $row,
            'The ratchet found a row for a capability the matrix does not list, so a missing row '
            . 'would read as a present one. What ships when it stays silent is a table that '
            . 'understates what an extension may be granted: the capability exists, the policy '
            . 'hands it out, and the document a reviewer reads does not mention it.',
        );
    }

    #[Test]
    public function itRefusesARowForACapabilityThatDoesNotExist(): void
    {
        // The defect in the other direction, and the more dangerous half: a row nothing in
        // the code can ever contradict, describing a restriction that is simply absent.
        $drifted = self::HEALTHY_MATRIX . "\n| `FilesystemPurge`     | yes  | no       | no        | no        |\n";

        $named = TrustTierTableScanner::matrixCapabilityNames($drifted);

        self::assertContains(
            'FilesystemPurge',
            $named,
            'The ratchet did not read the planted row at all, so a stale row would pass unseen. '
            . 'What ships when it stays silent is an ADR describing a capability the framework '
            . 'does not have — a restriction a reader will rely on and that nothing enforces, '
            . 'because there is nothing to enforce. It read: [' . implode(', ', $named) . ']',
        );

        $known = [];

        foreach (ExtensionCapability::cases() as $capability) {
            $known[] = $capability->name;
        }

        self::assertNotContains('FilesystemPurge', $known, 'the planted name must not be a real capability');
    }

    #[Test]
    public function itRefusesASummaryCellThatOverstatesTheGrant(): void
    {
        // The defect: a yes where the policy says no, in the table an operator reads to
        // decide what an extension can reach.
        $drifted = <<<'MARKDOWN'
            | Tier      | Container | Routes   | `EnvRead` | `ProcessExec` |
            | --------- | --------- | -------- | --------- | ------------- |
            | Community | Limited   | Prefixed | yes       | no            |
            MARKDOWN;

        $columns = TrustTierTableScanner::summaryColumns($drifted);
        $rows = TrustTierTableScanner::summaryRows($drifted);

        self::assertSame(
            [null, null, ExtensionCapability::EnvRead, ExtensionCapability::ProcessExec],
            $columns,
            'the header did not resolve to the capabilities it names, so the assertion below '
            . 'would be comparing cells against the wrong columns',
        );

        self::assertSame(
            'yes',
            $rows['Community'][2],
            'the planted cell was not read as planted, so the refusal below would be about '
            . 'something this test constructed rather than about the table',
        );

        self::assertFalse(
            CapabilityPolicy::defaults()->allows(TrustTier::Community, ExtensionCapability::EnvRead),
            'The ratchet agreed with a summary granting Community the environment reader. What '
            . 'ships when it stays silent is an operator concluding an unaudited extension can '
            . 'already read the framework\'s environment, and sizing the secrets in that process '
            . 'accordingly — from a table that is wrong.',
        );
    }

    #[Test]
    public function itRefusesASummaryWordThatOverstatesContainerReach(): void
    {
        // The graded columns drift the same way the yes/no ones do, and read as harmless
        // wording rather than as a claim. "Full" is ContainerWrite.
        $drifted = <<<'MARKDOWN'
            | Tier      | Container | Routes   | `EnvRead` | `ProcessExec` |
            | --------- | --------- | -------- | --------- | ------------- |
            | Verified  | Full      | Global   | yes       | no            |
            MARKDOWN;

        $rows = TrustTierTableScanner::summaryRows($drifted);
        $expected = TrustTierTableScanner::containerWord(CapabilityPolicy::defaults(), TrustTier::Verified);

        self::assertSame('Most', $expected, 'Verified may decorate and not override, so its word is Most');
        self::assertNotSame(
            $expected,
            $rows['Verified'][0],
            'The ratchet read "Full" for a tier the policy grants "Most" as agreement. What ships '
            . 'when it stays silent is one word — the word that separates a bundled product '
            . 'registering its own services from one able to replace the framework\'s session '
            . 'handling — drifting with nothing to catch it, because a word does not look like a '
            . 'claim the way a yes does. It read: ' . $rows['Verified'][0],
        );
    }

    #[Test]
    public function itRefusesTheConfigDocblockThatShipped(): void
    {
        // The defect, verbatim: this is what config/extensions.php told operators for the
        // whole of the rc series.
        $shipped = <<<'PHP'
            <?php
            /**
             * Trust tiers (highest to lowest):
             *   - core:       First-party framework extensions — full access
             *   - verified:   Audited third-party — all except `CryptoKeyAccess`, `ProcessExec`
             *   - untrusted:  Experimental/sandboxed — read-only container access
             */
            return [];
            PHP;

        $named = TrustTierTableScanner::docblockCapabilityNames($shipped);
        $withheld = TrustTierTableScanner::withheldFrom(CapabilityPolicy::defaults(), TrustTier::Verified);

        self::assertSame(['CryptoKeyAccess', 'ProcessExec'], $named, 'the planted docblock was not read as planted');
        self::assertNotSame(
            $withheld,
            $named,
            'The ratchet accepted the docblock that shipped. What ships when it stays silent is '
            . 'what did ship: the file an operator edits to raise an extension to verified, '
            . 'telling them that tier gets everything except two key operations, when it is also '
            . 'withheld ContainerWrite — the exclusion that stops the extension replacing Session, '
            . 'Auth or CsrfGuard with its own. They hand over a power they were told was not in '
            . 'the grant. It read: [' . implode(', ', $named) . '] against ['
            . implode(', ', $withheld) . ']',
        );
    }

    /**
     * The healthy case, asserted only so the six above cannot pass by accident.
     *
     * Every refusal above is an assertion that two things differ. If the scan returned
     * nothing at all, they would all differ, and all pass.
     */
    #[Test]
    public function itIsSilentOnTheSameDocumentsWithoutTheDefect(): void
    {
        $policy = CapabilityPolicy::defaults();

        self::assertSame(
            TrustTierTableScanner::grantedRow($policy, ExtensionCapability::ContainerWrite),
            TrustTierTableScanner::matrixRow(self::HEALTHY_MATRIX, ExtensionCapability::ContainerWrite),
        );
        self::assertSame(
            ['ContainerRead', 'ContainerWrite', 'ProcessExec'],
            TrustTierTableScanner::matrixCapabilityNames(self::HEALTHY_MATRIX),
        );

        $rows = TrustTierTableScanner::summaryRows(self::HEALTHY_SUMMARY);

        self::assertCount(4, $rows, 'the healthy summary must read as four tiers');

        foreach (TrustTierTableScanner::COLUMN_ORDER as $tier) {
            self::assertSame(TrustTierTableScanner::containerWord($policy, $tier), $rows[$tier->name][0]);
            self::assertSame(TrustTierTableScanner::routeWord($policy, $tier), $rows[$tier->name][1]);
            self::assertSame($policy->allows($tier, ExtensionCapability::EnvRead) ? 'yes' : 'no', $rows[$tier->name][2]);
            self::assertSame($policy->allows($tier, ExtensionCapability::ProcessExec) ? 'yes' : 'no', $rows[$tier->name][3]);
        }
    }
}
