<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\ProbeVerdict;
use Pulsar\Compliance\Frameworks\CcpaMapping;
use Pulsar\Compliance\Frameworks\HipaaMapping;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Frameworks\Soc2Mapping;
use Pulsar\Compliance\Probe\TransportSecurityProbe;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function implode;
use function sprintf;

/**
 * REACHABILITY: for every control this repair touched, a deployment shape that
 * FAILS it and a deployment shape that SATISFIES it, both built and both assessed.
 *
 * A control that can only ever reach one outcome is a broken instrument in
 * whichever direction it is stuck, and both directions were live in this
 * subsystem at once. HIPAA 164.312(e)(1) could not be Unsatisfied: it required
 * only the database link, so a deployment reaching its database over a unix
 * socket had no subject for the one fact it rested on and the control RETIRED
 * itself while serving ePHI over HTTP. SOC 2 C1.2 could not be Unsatisfied
 * either, for a different reason: one line of config claiming no personal data
 * was processed took it out of play, though it is about confidential information.
 *
 * This file is deliberately NOT a table of which fact belongs to which estate.
 * Such a table is hand-maintained, drifts silently from the code it describes,
 * and is the exact defect ADR-0045 removed from the mappings; a reachability
 * proof covers the same failure directly, because a control whose estate is
 * misnamed cannot reach one of its outcomes and this file would fail.
 *
 * Every shape below is the REAL fixture: the real composition root wires it, the
 * real gatherer observes it, the real probes conclude. Nothing here states an
 * outcome.
 */
#[CoversClass(ControlSubject::class)]
#[CoversClass(ProbeVerdict::class)]
#[CoversClass(TransportSecurityProbe::class)]
#[CoversClass(CcpaMapping::class)]
#[CoversClass(HipaaMapping::class)]
#[CoversClass(Iso27001Mapping::class)]
#[CoversClass(PciDssMapping::class)]
#[CoversClass(Soc2Mapping::class)]
final class ControlSubjectReachabilityTest extends TestCase
{
    /**
     * @var list<ComplianceFramework>
     */
    private const array FRAMEWORKS = [
        ComplianceFramework::Ccpa,
        ComplianceFramework::Hipaa,
        ComplianceFramework::Iso27001,
        ComplianceFramework::PciDss,
        ComplianceFramework::Soc2,
    ];

    // --- A5: transmission security can fail, and can pass ---------------------

    /**
     * The defect, in the shape that produced it: no database at all.
     *
     * The deployment serves requests over HTTP and asserts nothing about HSTS, so
     * transmission security is unprotected on the only link it has. This used to
     * report NotApplicable — out of the coverage denominator, off the assessor's
     * page — because the single fact behind it was about a database that is not
     * there.
     */
    #[Test]
    public function transmissionSecurityFailsOnADeploymentWithNoDatabaseAtAll(): void
    {
        foreach (self::transmissionControls() as [$framework, $id]) {
            $finding = self::equipped()->finding($framework, $id);

            self::assertSame(
                ControlOutcome::Unsatisfied,
                $finding->outcome,
                $framework->value . '/' . $id . ' evaporated instead of failing.',
            );
            self::assertTrue($finding->outcome->countsTowardCoverage());
            self::assertNotSame([], $finding->remediations);
        }
    }

    /**
     * And it fails on the shape the audit named: a database reached over a
     * network, in the clear.
     */
    #[Test]
    public function transmissionSecurityFailsOnAPlaintextNetworkedDatabase(): void
    {
        $deployment = self::equipped()->withNetworkedDatabase(sessionEncrypted: false)->withHstsEnforced();

        foreach (self::transmissionControls() as [$framework, $id]) {
            self::assertSame(ControlOutcome::Unsatisfied, $deployment->finding($framework, $id)->outcome);
        }
    }

    /**
     * The counter-assertion, and the half that keeps the repair from being a
     * blanket demotion: a deployment whose database session is confirmed encrypted
     * and whose HSTS is asserted SATISFIES both controls.
     *
     * The proof is `database_transport_encrypted`, measured — the server was asked
     * what it negotiated — and it carries a control about {@see ControlSubject::DataInTransit}
     * because the database link is a declared part of that estate. This is the one
     * place the estate vocabulary is not flat, and this test is why it is not.
     */
    #[Test]
    public function transmissionSecurityIsSatisfiedWhenTheSessionIsConfirmedEncrypted(): void
    {
        $deployment = self::equipped()->withNetworkedDatabase(sessionEncrypted: true)->withHstsEnforced();

        foreach (self::transmissionControls() as [$framework, $id]) {
            $finding = $deployment->finding($framework, $id);

            self::assertSame(
                ControlOutcome::Satisfied,
                $finding->outcome,
                $framework->value . '/' . $id . ' cannot be satisfied by any deployment.',
            );
            self::assertStringContainsString('pg_stat_ssl', $finding->summary);
        }
    }

    /**
     * NOT MEASURED, AND SAYING SO. HSTS is required, and it is a config read: it is
     * what the deployment asks a browser to do, and no code in this process can
     * confirm the TLS a proxy terminated in front of it. A "measured" fact there
     * would be a resolution wearing a Measured badge, which is the defect the whole
     * repair exists to remove.
     */
    #[Test]
    public function theHttpHalfOfTransmissionSecurityIsNeverProof(): void
    {
        $deployment = self::equipped()->withHstsEnforced();

        foreach (self::transmissionControls() as [$framework, $id]) {
            self::assertSame(
                ControlOutcome::Unsatisfied,
                $deployment->finding($framework, $id)->outcome,
                'HSTS being configured satisfied a transmission-security control on its own.',
            );
        }
    }

    // --- A6: a claim about personal data does not retire confidentiality ------

    /**
     * The A6 repair, executed. An entity that processes no personal data still
     * holds contracts, pricing and source code, and still has to dispose of them.
     */
    #[Test]
    public function assertingNoPersonalDataDoesNotRetireTheConfidentialityControls(): void
    {
        $deployment = self::equipped()->assertingScope('processes_personal_data', false);

        foreach ([['C1.2'], ['CC6.5']] as [$id]) {
            $finding = $deployment->finding(ComplianceFramework::Soc2, $id);

            self::assertSame(
                ControlOutcome::Unsatisfied,
                $finding->outcome,
                'soc2/' . $id . ' was retired by a claim about a different estate.',
            );
            self::assertTrue(
                $finding->outcome->countsTowardCoverage(),
                'A control that is still assessed must stay in the denominator.',
            );
        }
    }

    /**
     * And the same assertion still retires what it IS about, so the repair is a
     * statement about the estate rather than a weakening of scoping.
     */
    #[Test]
    public function assertingNoPersonalDataStillRetiresThePersonalDataControls(): void
    {
        $deployment = self::equipped()->assertingScope('processes_personal_data', false);

        self::assertSame(
            ControlOutcome::NotApplicable,
            $deployment->finding(ComplianceFramework::Ccpa, 'CCPA-1798.105')->outcome,
        );
        self::assertSame(
            ControlOutcome::NotApplicable,
            $deployment->finding(ComplianceFramework::Soc2, 'P1.2')->outcome,
        );
    }

    /**
     * No widening in the other direction either. The data classes are siblings in
     * {@see ControlSubject}, so one assertion cannot reach a standard it was never
     * written about — a personal-data claim leaves PCI Req 3.4 and the HIPAA
     * encryption controls assessed.
     */
    #[Test]
    public function assertingNoPersonalDataDoesNotReachTheOtherStandardsAssertions(): void
    {
        $deployment = self::equipped()->assertingScope('processes_personal_data', false);

        self::assertNotSame(
            ControlOutcome::NotApplicable,
            $deployment->finding(ComplianceFramework::PciDss, 'Req3.4')->outcome,
        );
        self::assertNotSame(
            ControlOutcome::NotApplicable,
            $deployment->finding(ComplianceFramework::Hipaa, '164.312(a)(2)(iv)')->outcome,
        );
    }

    // --- The other direction, recorded rather than traded quietly -------------

    /**
     * PCI Req 3.4 is the proof that the estate join is not a blanket demotion: it
     * reaches BOTH outcomes, on two shapes that differ only in whether the vault
     * can do its work.
     *
     * The measurement behind it — {@see \Pulsar\Compliance\Evidence\TokenVaultObserver}
     * tokenizing a value, reading the persisted bytes back and removing it again —
     * is about the PAN estate, which is what Req 3.4 regulates. It is also the
     * template for what would unstick the controls named below.
     */
    #[Test]
    public function panAtRestReachesBothOutcomes(): void
    {
        self::assertSame(
            ControlOutcome::Satisfied,
            self::equipped()->finding(ComplianceFramework::PciDss, 'Req3.4')->outcome,
        );
        self::assertSame(
            ControlOutcome::Unsatisfied,
            DeploymentUnderAssessment::fullyEquipped(self::FRAMEWORKS)
                ->withUnusableTokenVault()
                ->finding(ComplianceFramework::PciDss, 'Req3.4')
                ->outcome,
        );
    }

    /**
     * AND THE COST, PROVED BY EXECUTION RATHER THAN CONCEDED IN PROSE.
     *
     * The estate join left the audit-trail controls unable to reach Satisfied on
     * any deployment this release can build. Everything they require is present
     * here — the sink resolves, the register verifies, the health checks run — and
     * the only measurement in reach interrogates the compliance evidence register,
     * which is a different estate from the audit trail ISO 27001 A.8.15 and PCI Req
     * 10.2 regulate.
     *
     * That is a control stuck in one direction, which this file's own opening
     * paragraph calls broken. It is recorded here, with the remedy named, so that
     * it is a scheduled piece of work rather than a silence: an observer that
     * writes an audit event through the bound AuditSinkInterface and reads it back
     * — the shape TokenVaultObserver already has for the vault — makes
     * {@see ControlSubject::AuditTrail} measurable and this test will then have to
     * be rewritten to assert the pass it currently cannot get. See ADR-0062.
     */
    #[Test]
    public function theAuditTrailControlsCannotYetReachSatisfiedAndTheFindingSaysWhy(): void
    {
        $deployment = self::equipped();

        foreach ([[ComplianceFramework::Iso27001, 'A.8.15'], [ComplianceFramework::PciDss, 'Req10.2']] as [$f, $id]) {
            $finding = $deployment->finding($f, $id);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
            self::assertStringContainsString(
                ControlSubject::ComplianceEvidenceRegister->value,
                $finding->summary,
                'The finding must name the estate that WAS interrogated.',
            );
            self::assertStringContainsString(
                ControlSubject::AuditTrail->value,
                $finding->summary,
                'And the estate the control regulates, so the two can be told apart.',
            );
            self::assertStringContainsString(
                'Do not re-do that work',
                implode(' ', $finding->remediations),
                'An operator whose sink is bound and whose chain verifies must not be told to '
                    . 'bind a sink and record a verification run.',
            );
        }
    }

    /**
     * No dead estate. Every case in {@see ControlSubject} is either the estate a
     * fact interrogates or the estate a control regulates.
     *
     * Computed from the enum and the catalogue, with no list written down here —
     * a vocabulary that grows cases nobody uses is how a narrow vocabulary stops
     * being narrow, and an estate invented "for later" is one an author will
     * eventually reach for because it sounds right rather than because a fact or a
     * standard named it.
     *
     * Note what this does NOT assert: that every estate has a MEASURABLE fact.
     * Three do not — `access_control`, `confidential_information` and the parent
     * `data_in_transit`, whose parts carry it — and asserting otherwise would be
     * asserting that this release observes more than it does.
     */
    #[Test]
    public function everyEstateInTheVocabularyIsUsedBySomethingReal(): void
    {
        $used = [];

        foreach (ObservationId::cases() as $id) {
            $used[$id->subject()->value] = true;
        }

        foreach (self::declaredEstates() as $estate) {
            $used[$estate->value] = true;

            foreach (ControlSubject::cases() as $candidate) {
                // A parent estate is used by its parts too: DataInTransit is what
                // HIPAA 164.312(e)(1) declares, and the facts underneath it name
                // the links rather than the whole.
                if ($estate->covers($candidate)) {
                    $used[$candidate->value] = true;
                }
            }
        }

        foreach (ControlSubject::cases() as $estate) {
            self::assertArrayHasKey(
                $estate->value,
                $used,
                sprintf(
                    'ControlSubject::%s is declared by no control and interrogated by no fact. '
                        . 'Delete it, or name the control it was added for.',
                    $estate->name,
                ),
            );
        }
    }

    /**
     * The estate every probed control in the five frameworks under test declares.
     *
     * @return list<ControlSubject>
     */
    private static function declaredEstates(): array
    {
        $estates = [];

        foreach (self::everyDeclaration() as $declaration) {
            if ($declaration->isProbed()) {
                $estates[] = $declaration->assessedSubject();
            }
        }

        return $estates;
    }

    /**
     * @return list<ControlDeclaration>
     */
    private static function everyDeclaration(): array
    {
        return [
            ...CcpaMapping::declarations(),
            ...HipaaMapping::declarations(),
            ...Iso27001Mapping::declarations(),
            ...PciDssMapping::declarations(),
            ...Soc2Mapping::declarations(),
        ];
    }

    /**
     * @return list<array{ComplianceFramework, non-empty-string}>
     */
    private static function transmissionControls(): array
    {
        return [
            [ComplianceFramework::Hipaa, '164.312(e)(1)'],
            [ComplianceFramework::Soc2, 'CC6.7'],
        ];
    }

    private static function equipped(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::fullyEquipped(self::FRAMEWORKS);
    }
}
