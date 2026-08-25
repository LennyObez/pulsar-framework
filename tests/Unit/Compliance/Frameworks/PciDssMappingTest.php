<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Evidence\TokenVaultObserver;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\PanAtRestProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Crypto\DatabaseTokenStore;
use Pulsar\Security\Crypto\InMemoryTokenStore;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function array_filter;
use function count;

/**
 * PCI-DSS, assessed against deployments that do and do not have the thing.
 *
 * The test this replaces read `status: ControlStatus::Implemented` out of the
 * mapping file and asserted `ControlStatus::Implemented` back. It passed on every
 * commit of the ADR-0041 defect, because the only thing it could ever detect was
 * someone editing the literal it was reading.
 *
 * Its successor then passed on the NEXT version of the same defect: it asserted
 * that Req 3.4 was Satisfied when `DatabaseTokenStore` resolved, and built that
 * store without its constructor, so the control it certified was carried by a
 * vault that could not have held a single token. {@see aBoundButUnusableVaultDoesNotSatisfyPanAtRest()}
 * is the assertion that was missing, and it fails against a probe that only reads
 * which class answered.
 */
#[CoversClass(PciDssMapping::class)]
#[CoversClass(PanAtRestProbe::class)]
#[CoversClass(TokenVaultObserver::class)]
#[CoversClass(MultiFactorAuthenticationProbe::class)]
#[CoversClass(TamperEvidentAuditProbe::class)]
final class PciDssMappingTest extends TestCase
{
    private const string TOKEN_STORE = 'Pulsar\Security\Crypto\TokenStoreInterface';
    private const string AUDIT_SINK = 'Pulsar\Security\Audit\AuditSinkInterface';
    private const string TWO_FACTOR = 'Pulsar\Auth\TwoFactor\TwoFactorManagerInterface';

    #[Test]
    public function declaresSevenControls(): void
    {
        self::assertCount(7, PciDssMapping::declarations());
    }

    // --- Req 3.4: the original ADR-0041 control -----------------------------

    #[Test]
    public function panAtRestIsAGapWhenNoTokenStoreResolves(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.pan_at_rest', $finding->probeId);
        self::assertNotSame([], $finding->remediations);
    }

    /**
     * The exact deployment ADR-0041 describes. The catalogue used to report this
     * as Implemented; it is a gap, and the evidence names the class responsible.
     */
    #[Test]
    public function panAtRestIsAGapWhenTheVaultIsTheInMemoryOne(): void
    {
        $finding = self::bare()
            ->resolving(self::TOKEN_STORE, InMemoryTokenStore::class)
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString(
            InMemoryTokenStore::class,
            self::evidenceText($finding->evidence),
        );
        self::assertStringContainsString('lost on restart', self::evidenceText($finding->evidence));
    }

    /**
     * ADR-0041's fix, and the defect that survived it.
     *
     * `TokenStoreInterface` resolves to `DatabaseTokenStore` — the durable
     * implementation, the one the ADR prescribed — against a database with no
     * `token_vault` table. The identity fact is present and clean. The vault
     * throws on the first tokenize() and renders nothing unreadable, and the
     * control must say so.
     */
    #[Test]
    public function aBoundButUnusableVaultDoesNotSatisfyPanAtRest(): void
    {
        $finding = self::bare()
            ->withUnusableTokenVault()
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);

        $evidence = self::evidenceText($finding->evidence);

        // The identity fact still reads clean; the difference is entirely in what
        // happened when the vault was used.
        self::assertStringContainsString(DatabaseTokenStore::class, $evidence);
        self::assertStringContainsString('bound but not usable', $evidence);
    }

    #[Test]
    public function panAtRestIsSatisfiedOnlyWhenTheVaultActuallyRendersAValueUnreadable(): void
    {
        $finding = self::bare()
            ->withWorkingTokenVault()
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);

        $evidence = self::evidenceText($finding->evidence);

        self::assertStringContainsString(DatabaseTokenStore::class, $evidence);
        self::assertStringContainsString('the persisted form concealed it', $evidence);
    }

    /**
     * The measurement cleans up after itself. A report that accumulated its own
     * probe rows in the vault it reports on would be changing the thing it
     * measures, one run at a time.
     */
    #[Test]
    public function theVaultMeasurementRemovesWhatItWrote(): void
    {
        $finding = self::bare()
            ->withWorkingTokenVault()
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertStringContainsString(
            'the probe mapping was removed',
            self::evidenceText($finding->evidence),
        );
    }

    /**
     * A satisfied verdict must rest on something observed, never on a setting.
     * Every observation behind it carries the grade it was obtained at, and at
     * least one of them has to prove behaviour.
     */
    #[Test]
    public function aSatisfiedPanControlRestsOnObservedBehaviour(): void
    {
        $finding = self::bare()
            ->withWorkingTokenVault()
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        $admissible = array_filter(
            $finding->evidence,
            static fn(object $observation): bool => $observation->isAdmissibleAsProof(),
        );

        self::assertNotSame([], $admissible);
    }

    /**
     * The control's own words are "anywhere it is stored", and Pulsar sees one
     * place. Saying so in the requirement text is the difference between a
     * narrowed control and an overstated one, and the report prints that text.
     */
    #[Test]
    public function theNarrowedScopeOfPanAtRestIsStatedWhereAReaderWillSeeIt(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertStringContainsString('ASSESSED NARROWLY', $finding->declaration->requirement);
        self::assertStringContainsString(
            'never that no PAN is stored readable elsewhere',
            $finding->declaration->requirement,
        );
    }

    /**
     * And the residual it names is a declared control of its own, not a footnote.
     */
    #[Test]
    public function theStorageInventoryIsItsOwnOperatorControl(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req1.1.3');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertStringContainsString('inventory of every location', $finding->declaration->operatorArtefact);
        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    #[Test]
    public function panAtRestIsNotApplicableOnlyOnTheOperatorsRecordedAssertion(): void
    {
        $finding = self::bare()
            ->assertingScope('stores_cardholder_data', false)
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertSame(ControlOutcome::NotApplicable, $finding->outcome);

        // The report must be able to show WHO said so and WHERE, so the claim can
        // be challenged in one question rather than believed.
        self::assertStringContainsString(
            'scope.stores_cardholder_data = false',
            self::evidenceText($finding->evidence),
        );
        self::assertStringContainsString('asserted by the operator', self::evidenceText($finding->evidence));
    }

    #[Test]
    public function aNotApplicableControlIsExcludedFromCoverage(): void
    {
        $finding = self::bare()
            ->assertingScope('stores_cardholder_data', false)
            ->finding(ComplianceFramework::PciDss, 'Req3.4');

        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    // --- Req 10.2: audit trail ----------------------------------------------

    #[Test]
    public function auditTrailIsAGapWhenNoSinkAndNoChainExist(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req10.2');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.tamper_evident_audit', $finding->probeId);
    }

    #[Test]
    public function auditTrailIsSatisfiedWhenASinkResolvesAndTheChainVerifies(): void
    {
        $finding = self::bare()
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain()
            ->finding(ComplianceFramework::PciDss, 'Req10.2');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);

        $evidence = self::evidenceText($finding->evidence);

        // The verdict word and the counts both, because "the register is INTACT"
        // without "1 record(s) present, 1 attested by the anchor" is the sentence
        // that used to be printed over a truncated register.
        self::assertStringContainsString('register is INTACT', $evidence);
        self::assertStringContainsString('attested by the anchor', $evidence);
    }

    /**
     * A sink with no verifiable chain is a log, not an audit trail — and the log
     * itself was never exercised either.
     *
     * This read Partial while resolved identity proved behaviour: the audit sink
     * RESOLVED, so half the control counted as observed. Neither half was. The
     * chain recomputation is the only thing here that runs, it did not run, and the
     * outcome is a gap naming both the sink it found and what is missing.
     */
    #[Test]
    public function auditTrailIsAGapWhenTheSinkResolvesButNoChainVerifies(): void
    {
        $finding = self::bare()
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->finding(ComplianceFramework::PciDss, 'Req10.2');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('AuditFileSink', self::evidenceText($finding->evidence));
        self::assertNotSame([], $finding->remediations);
    }

    // --- Req 8.3 / 8.1.1: authentication ------------------------------------

    #[Test]
    public function multiFactorAuthenticationIsAGapWithNoTwoFactorSubsystem(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req8.3');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    /**
     * Req 8.3 rested entirely on `TwoFactorManager` being the class bound to the
     * MFA contract, which is the shape of claim ADR-0041 was written about. Pulsar
     * cannot exercise a second factor from a report — that needs a user — so the
     * honest outcome is a gap naming the subsystem it found, with the remediation
     * beside it.
     */
    #[Test]
    public function multiFactorAuthenticationIsClaimedAndNotObservedWhenTheManagerResolves(): void
    {
        $finding = self::bare()
            ->resolving(self::TWO_FACTOR, 'Pulsar\Auth\TwoFactor\TwoFactorManager')
            ->finding(ComplianceFramework::PciDss, 'Req8.3');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString('TwoFactorManager', $finding->summary);
        self::assertNotSame([], $finding->remediations);
    }

    /**
     * The MFA probe used to carry a control declaring unique identification for
     * all users. Those are different requirements, and only one of them is
     * observable: whether two people share a login is a fact about how accounts
     * are issued, not about which classes are bound.
     */
    #[Test]
    public function uniqueIdentificationIsAnOperatorResponsibility(): void
    {
        $finding = self::bare()
            ->resolving(self::TWO_FACTOR, 'Pulsar\Auth\TwoFactor\TwoFactorManager')
            ->finding(ComplianceFramework::PciDss, 'Req8.1.1');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertStringContainsString('no shared or generic credential', $finding->declaration->operatorArtefact);
    }

    // --- Req 2.3: a subject outside the process ------------------------------

    /**
     * Req 2.3's subject is the channel an administrator's traffic crosses, and it
     * was decided by the database session and the session cipher — a deployment on
     * SQLite reached Satisfied on the sentence "SQLite is a local file; the session
     * has no network transport to encrypt". TLS termination happens in the web
     * server or at the edge, and a report generated from the CLI has no request to
     * inspect, so there is nothing here for a probe to observe and the control says
     * so.
     */
    #[Test]
    public function encryptedAdministrativeAccessIsAnOperatorResponsibility(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req2.3');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertStringContainsString('cipher scan', $finding->declaration->operatorArtefact);
        self::assertFalse($finding->outcome->countsTowardCoverage());
        self::assertFalse($finding->isFailing(strict: true));
    }

    // --- Req 6.5: not a property of the deployment at all --------------------

    /**
     * Whether the quality gate passed for the deployed commit is a fact about a CI
     * run somewhere else. The declaration carries no probe — the type forbids it —
     * names the artefact an assessor should demand, and cannot move the report's
     * exit code in either direction.
     */
    #[Test]
    public function codingVulnerabilitiesIsAnOperatorResponsibilityNamingItsArtefact(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::PciDss, 'Req6.5');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertFalse($finding->declaration->isProbed());
        self::assertStringContainsString('CI run', $finding->declaration->operatorArtefact);
        self::assertFalse($finding->outcome->countsTowardCoverage());
        self::assertFalse($finding->isFailing(strict: true));
    }

    private static function bare(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::PciDss]);
    }

    /**
     * @param list<object> $evidence
     */
    private static function evidenceText(array $evidence): string
    {
        $text = '';

        foreach ($evidence as $observation) {
            /** @var object{detail: string} $observation */
            $text .= $observation->detail . "\n";
        }

        self::assertGreaterThan(0, count($evidence), 'A finding must carry the evidence it rests on.');

        return $text;
    }
}
