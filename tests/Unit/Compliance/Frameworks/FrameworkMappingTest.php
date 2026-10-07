<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Frameworks\AiActMapping;
use Pulsar\Compliance\Frameworks\CcpaMapping;
use Pulsar\Compliance\Frameworks\DoraMapping;
use Pulsar\Compliance\Frameworks\EidasMapping;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\HipaaMapping;
use Pulsar\Compliance\Frameworks\Hl7FhirMapping;
use Pulsar\Compliance\Frameworks\Iso13485Mapping;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;
use Pulsar\Compliance\Frameworks\MdrMapping;
use Pulsar\Compliance\Frameworks\Nis2Mapping;
use Pulsar\Compliance\Frameworks\NistCsfMapping;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Frameworks\Psd2Mapping;
use Pulsar\Compliance\Frameworks\Soc2Mapping;
use Pulsar\Compliance\Frameworks\SwiftCspMapping;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function array_filter;
use function array_map;
use function array_sum;
use function count;
use function sprintf;

/**
 * Every framework's declarations, assessed against a deployment that has nothing
 * and one that has everything this release can observe.
 *
 * This is the test the per-framework literal tests should always have been.
 * Sixteen files used to read a hard-coded `ControlStatus` out of a mapping and
 * assert it back — 207 assertions that could only ever detect somebody editing
 * the literal they were reading, and that stayed green through the entire life of
 * the ADR-0041 defect they were nominally covering.
 */
#[CoversClass(AiActMapping::class)]
#[CoversClass(CcpaMapping::class)]
#[CoversClass(DoraMapping::class)]
#[CoversClass(EidasMapping::class)]
#[CoversClass(GdprMapping::class)]
#[CoversClass(HipaaMapping::class)]
#[CoversClass(Hl7FhirMapping::class)]
#[CoversClass(Iso13485Mapping::class)]
#[CoversClass(Iso27001Mapping::class)]
#[CoversClass(Iso42001Mapping::class)]
#[CoversClass(MdrMapping::class)]
#[CoversClass(Nis2Mapping::class)]
#[CoversClass(NistCsfMapping::class)]
#[CoversClass(PciDssMapping::class)]
#[CoversClass(Psd2Mapping::class)]
#[CoversClass(Soc2Mapping::class)]
#[CoversClass(SwiftCspMapping::class)]
#[CoversClass(ControlCatalog::class)]
#[CoversClass(ControlAssessment::class)]
#[CoversClass(ControlDeclaration::class)]
#[CoversClass(ControlSubject::class)]
final class FrameworkMappingTest extends TestCase
{
    /** Every control the framework declares, across all seventeen core mappings. */
    private const int TOTAL_CONTROLS = 215;

    /**
     * What a deployment carrying every implementation this release assesses can
     * actually be observed doing, per framework.
     *
     * Recorded rather than thresholded. See
     * {@see anEquippedDeploymentSatisfiesExactlyWhatItCanMeasure()} for what these
     * numbers mean and what moving one of them requires.
     *
     * THE TOTAL IS 19, DOWN FROM 32, and the whole of that movement is set out
     * below: the framework, the figure it left, the figure it holds, and the
     * reason. Every row was produced by running the assessment against
     * {@see equipped()}; none of it was reasoned to. Five decisions are in play —
     * ADR-0061 regrading `extension_loaded('sodium')` to Available, ADR-0062
     * requiring a fact to be about the control's own estate, and ADR-0063 to
     * ADR-0066 adding five observers that measure a subsystem instead of naming
     * it.
     *
     *   ai_act     0 -> 1  ai-act-art-50-capability rests on the transparency
     *                      subsystem being exercised (ADR-0063), not on a class
     *                      name. This mapping used to satisfy nothing on any
     *                      shape at all.
     *   ccpa       1 -> 1  Unchanged figure, same control, different evidence
     *                      under it. 1798.150 rested on the loaded extension and
     *                      lost it (ADR-0061); it came back on the personal-data
     *                      seal (ADR-0066), which is a measurement on the estate
     *                      it is declared over. 1798.105 and 1798.185 are
     *                      unaffected.
     *   dora       1 -> 0  DORA-INC-002 is about the audit trail and rested on
     *                      the compliance evidence register (ADR-0062).
     *   eidas      0 -> 0  Nothing here observes a signature being created or
     *                      validated, and nothing did before.
     *   gdpr       2 -> 4  Art 25 and Art 33 arrived, measured (ADR-0065) — the
     *                      two ADR-0046 left `compliance:check` failing on. Art
     *                      5(1)(f) and Art 32 left on the extension regrade and
     *                      the estate join, and came back on the personal-data
     *                      seal (ADR-0066): both are declared over personal data,
     *                      and that estate is now measured rather than inferred
     *                      from a loaded extension. All four rest on behaviour.
     *   hipaa      4 -> 0  Two encryption controls on the extension regrade
     *                      (ADR-0061); 164.312(b) and (c)(1) are about the audit
     *                      trail and rested on the evidence register (ADR-0062).
     *   hl7_fhir   1 -> 0  FHIR-AUDIT-001, audit trail, evidence register.
     *   iso13485   2 -> 1  TRACE-001, audit trail, evidence register. What is
     *                      left is ISO13485-MONITOR-001 on the health checks.
     *   iso27001   3 -> 1  A.8.15 (audit trail) and A.8.9 (configuration, carried
     *                      by liveness checks) both on the estate join. A.8.24
     *                      stands, on the KDF running against the key in service.
     *   iso42001   1 -> 0  9.2 is internal audit of the AI management system and
     *                      rested on the evidence register.
     *   mdr        2 -> 1  TRACE-001, audit trail, evidence register.
     *                      MDR-PMS-001 stands on the health checks.
     *   nis2       1 -> 1  Unchanged figure, different control. Art 21(h) left on
     *                      the extension regrade; Art 23 arrived on the register
     *                      measurement (ADR-0065).
     *   nist_csf   3 -> 1  PR.DS on the extension regrade, DE.AE (audit trail) on
     *                      the estate join. DE.CM stands on the health checks.
     *   pci_dss    2 -> 1  Req 10.2, audit trail, evidence register. Req 3.4
     *                      stands on the token vault rendering a value unreadable.
     *   psd2       1 -> 0  Art 94, audit trail, evidence register.
     *   soc2       5 -> 4  CC5.3 is about configuration and was carried by the
     *                      liveness checks. C1.2 and CC6.5 moved too, out of
     *                      NotApplicable rather than out of Satisfied, so they do
     *                      not touch this figure.
     *   swift_csp  3 -> 3  Unchanged figure, different control. 6.4 left (audit
     *                      trail, evidence register); 2.6 arrived on the session
     *                      seal (ADR-0064) and is the only control in the
     *                      catalogue declared over SessionPayloads.
     *
     * Sum 19. `withNothing` is 0 and has never been anything else.
     *
     * WHAT ADR-0066 DID NOT MOVE, recorded because a reader will expect it to:
     * hipaa stays 0 and nist_csf stays 1. The personal-data seal interrogates
     * `personal_data`, and HIPAA's two encryption controls are declared over
     * `health_data` while NIST PR.DS is declared over `confidential_information`.
     * Those estates are SIBLINGS in {@see ControlSubject} and deliberately do not
     * nest, so a measurement of one says nothing about the others. Widening the
     * fact to cover them is the move that would put the loaded extension back.
     *
     * @var array<string, int>
     */
    private const array SATISFIED_WHEN_EQUIPPED = [
        'ai_act' => 1,
        'ccpa' => 1,
        'dora' => 0,
        'eidas' => 0,
        'gdpr' => 4,
        'hipaa' => 0,
        'hl7_fhir' => 0,
        'iso13485' => 1,
        'iso27001' => 1,
        'iso42001' => 0,
        'mdr' => 1,
        'nis2' => 1,
        'nist_csf' => 1,
        'pci_dss' => 1,
        'psd2' => 0,
        'soc2' => 4,
        'swift_csp' => 3,
    ];

    /**
     * @return list<ControlDeclaration>
     */
    private static function declarations(): array
    {
        return [
            ...AiActMapping::declarations(),
            ...CcpaMapping::declarations(),
            ...DoraMapping::declarations(),
            ...EidasMapping::declarations(),
            ...GdprMapping::declarations(),
            ...HipaaMapping::declarations(),
            ...Hl7FhirMapping::declarations(),
            ...Iso13485Mapping::declarations(),
            ...Iso27001Mapping::declarations(),
            ...Iso42001Mapping::declarations(),
            ...MdrMapping::declarations(),
            ...Nis2Mapping::declarations(),
            ...NistCsfMapping::declarations(),
            ...PciDssMapping::declarations(),
            ...Psd2Mapping::declarations(),
            ...Soc2Mapping::declarations(),
            ...SwiftCspMapping::declarations(),
        ];
    }

    #[Test]
    public function everyMappingDeclaresItsControlsWithoutCollision(): void
    {
        $catalog = new ControlCatalog();

        // Registration refuses duplicates, so this also proves no two mappings
        // claim the same control of the same framework.
        $catalog->register(...self::declarations());

        self::assertSame(self::TOTAL_CONTROLS, $catalog->count());
    }

    /**
     * A probed control must have a probe and an operator-responsibility control
     * must name its artefact. Both are guaranteed by the type, and this asserts
     * the guarantee holds across every declaration in the tree.
     */
    #[Test]
    public function everyDeclarationIsEitherProbedOrNamesAnAssessorArtefact(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            if ($declaration->isProbed()) {
                self::assertNotNull($declaration->probe, $label);
                self::assertSame('', $declaration->operatorArtefact, $label);

                continue;
            }

            self::assertNull($declaration->probe, $label);
            self::assertNotSame(
                '',
                $declaration->operatorArtefact,
                sprintf(
                    '%s has no probe, so it must name the artefact an assessor should be shown; '
                        . 'otherwise it is a control quietly excluded from coverage and from view.',
                    $label,
                ),
            );
        }
    }

    /**
     * The requirement text is the standard's, not a description of Pulsar. The
     * sentence "Covered by TokenizationService … and DatabaseTokenStore" was the
     * false claim ADR-0041 found; no requirement may name a framework class again.
     */
    #[Test]
    public function noRequirementTextClaimsCoverageByNamingAFrameworkClass(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            foreach (['Covered by', 'Pulsar', 'Framework provides'] as $claim) {
                self::assertStringNotContainsString($claim, $declaration->requirement, $label);
            }
        }
    }

    #[Test]
    public function everyDeclarationCarriesAnIdentifierATitleAndARequirement(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            self::assertNotSame('', $declaration->id, $label);
            self::assertNotSame('', $declaration->title, $label);
            self::assertNotSame('', $declaration->requirement, $label);
        }
    }

    // --- The deployment-shaped assertions ------------------------------------

    /**
     * A deployment with nothing wired satisfies nothing, anywhere.
     */
    #[Test]
    #[DataProvider('everyDeclaredFramework')]
    public function nothingIsSatisfiedOnADeploymentThatHasNothing(ComplianceFramework $framework): void
    {
        $findings = self::assess($framework, self::bare());

        self::assertNotSame([], $findings, $framework->value);

        foreach ($findings as $finding) {
            self::assertNotSame(
                ControlOutcome::Satisfied,
                $finding->outcome,
                sprintf(
                    '%s/%s reported Satisfied on a deployment with no services, no profile and '
                        . 'no extensions.',
                    $framework->value,
                    $finding->declaration->id,
                ),
            );
        }
    }

    /**
     * And a deployment carrying every implementation this release assesses
     * satisfies EXACTLY these controls — written down per framework, because the
     * number is the honest output of the design and hiding it behind a threshold
     * is how a compliance subsystem drifts.
     *
     * The figures fell hard when `ObservationGrade::provesBehaviour()` was narrowed
     * to Measured. Across the seventeen mappings a fully-equipped deployment used to
     * satisfy 78 of 96 assessed controls; it then satisfied 32. The 46 that moved
     * did not get worse — nothing about the deployment changed — they were passing
     * on resolved identity, which answers "which class is bound, and is it on the
     * allow-list" and is ADR-0041's defect one lookup deeper. Their findings now
     * read "Claimed and not observed" and name the class that was found.
     *
     * THEY FELL AGAIN, FROM 32 TO 25, when `runtime.sodium_extension` was regraded
     * from Measured to Available. That check is `extension_loaded('sodium')` plus
     * one `function_exists`, and at grade Measured it was admissible proof: nine
     * controls across seven frameworks were Satisfied by a loaded PHP extension.
     * Seven of the nine moved to Unsatisfied — CCPA 1798.150, GDPR Art 5(1)(f) and
     * Art 32, HIPAA 164.312(a)(2)(iv) and its 2026 twin, NIS2 Art 21(h), NIST CSF
     * PR.DS. The other two kept their Satisfied because a real measurement stands
     * under each: ISO 27001 A.8.24 on the KDF running against the key in service,
     * and PCI DSS Req 3.4 on the token vault rendering a value unreadable. Which
     * two survived is the evidence that the regrade demoted a FACT rather than a
     * set of controls; see
     * {@see \Pulsar\Tests\Unit\Compliance\Evidence\RuntimeFactGradeTest}.
     *
     * AND FROM 25 TO 11 when {@see \Pulsar\Compliance\Control\ControlSubject}
     * arrived and a fact stopped being able to prove a control about a different
     * estate. Nineteen findings moved. SEVENTEEN of them are the proof join, in
     * three groups, and every one of those was a measurement of something the
     * control does not regulate:
     *
     *  - TWELVE were carried by `audit_chain_verified`, which recomputes HMACs
     *    over the COMPLIANCE EVIDENCE REGISTER — the framework's signed log of its
     *    own verification runs. Eleven of the twelve regulate the AUDIT TRAIL the
     *    application writes through AuditSinkInterface (HIPAA 164.312(b), PCI Req
     *    10.2, ISO 27001 A.8.15, NIST DE.AE, PSD2 Art 94, DORA-INC-002,
     *    FHIR-AUDIT-001, ISO 13485 TRACE-001, MDR TRACE-001, SWIFT 6.4, HIPAA
     *    164.312(c)(1)); the twelfth, ISO 42001 9.2, regulates the AI management
     *    system. Nothing in this release exercises either.
     *  - TWO were carried by `health_checks_executed` for controls about the
     *    deployment's CONFIGURATION (ISO 27001 A.8.9, SOC 2 CC5.3). Executing the
     *    liveness checks a deployment happened to register is not a fact about how
     *    it is configured.
     *  - THREE were the Partial verdicts on ISO 42001 9.1, 10.1 and A.7, where the
     *    "part observed" was again the generic health checks and the AI monitoring
     *    hook has no implementation anywhere in the tree.
     *
     * THE OTHER TWO ARE THE SCOPE JOIN, not the proof join, and they moved in the
     * opposite direction: SOC 2 C1.2 and CC6.5 came OUT of NotApplicable. One line
     * of config — `scope.processes_personal_data = false` — had been retiring two
     * Confidentiality criteria, and an entity that processes no personal data
     * still holds contracts and source code it has to dispose of. They are
     * assessed now and they fail, because this release measures no data disposal
     * at all; being failed by a real gap is not the same as being satisfiable, and
     * neither of them touches the count below.
     *
     * That left eleven, resting on three measurements that ARE about their
     * control's estate: the health checks for nine operational-monitoring controls,
     * the KDF running against the key in service for ISO 27001 A.8.24, and the
     * token vault rendering a value unreadable for PCI Req 3.4.
     *
     * AND THEN ONE CAME BACK, WHICH IS THE FIRST MOVEMENT IN THE OTHER DIRECTION.
     * `ai_act` went from 0 to 1: `ai-act-art-50-capability` now rests on
     * {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver}, which declares a
     * surface through the live transparency subsystem, reads the policy back with
     * both Article 50 duties intact, mints a synthetic-content mark and checks that
     * a mark is REFUSED for a surface nobody declared. It used to rest on
     * `ai_transparency_resolved`, a class name — and on a fact that could not be
     * present on any deployment at all, because the composition root resolved eight
     * of the AI governance contracts and not that one. The EU AI Act mapping
     * satisfied nothing on any shape this fixture can build, which is a control
     * stuck in one direction and the same broken instrument the paragraph above
     * spends itself objecting to.
     *
     * AND ONE MORE CAME BACK, FOR THE SAME REASON AND ON A DIFFERENT ESTATE.
     * `swift_csp` went from 2 to 3: SWIFT CSP 2.6, operator session
     * confidentiality and integrity, now rests on
     * {@see \Pulsar\Compliance\Evidence\SessionSealObserver}, which seals a
     * synthetic payload through the live session cipher, opens it, modifies one
     * byte and offers the same bytes as another session. It used to rest on
     * `session_encryption_resolved` alone — a config read saying the cipher class
     * had been constructed — so it could not be Satisfied by any deployment at
     * all, while the fact standing beside it in the neighbouring probes was
     * `extension_loaded('sodium')`. That is exactly one control, and the estate
     * is why: the fact interrogates SESSION PAYLOADS, so it carries the one
     * control declared over that estate and none of the personal-data,
     * health-data or confidential-information controls whose probes now require
     * it. Widening the estate to reach them is how nine controls came to rest on
     * a loaded extension in the first place.
     *
     * AND THREE MORE, WHICH ARE THE TWO GATES ADR-0046 LEFT FAILING ON PURPOSE.
     * `gdpr` went from 0 to 2 and `nis2` from 0 to 1, and unlike the two above
     * these controls were not merely unreachable — `composer compliance:check`
     * failed on a default installation because of them, deliberately, and
     * config/compliance.php named the only honest way to close them.
     *
     *  - GDPR Art 25 rests on {@see \Pulsar\Compliance\Evidence\PseudonymizationObserver},
     *    which pseudonymises a synthetic identifier, reads the mapping back,
     *    resolves it byte for byte and then ERASES it through the service Article
     *    17 names. It used to rest on `pseudonymization_resolved`, a class name.
     *  - GDPR Art 33 and NIS2 Art 23 rest on
     *    {@see \Pulsar\Compliance\Evidence\IncidentRegisterObserver}, which records
     *    an incident through the live register and reads it back by id with its
     *    severity, title, metadata and timestamp intact — the clock a 72-hour and a
     *    24-hour deadline are measured from. They used to rest on
     *    `incident_reporter_resolved`: a register that WOULD survive a restart,
     *    with nothing ever written to it.
     *
     * BOTH KEPT A SECOND, ESSENTIAL FACT BESIDE THE MEASUREMENT, and the reason is
     * the same in both: the measurement runs in ONE process. The development stub
     * `InMemoryPseudonymLookup` and `InMemoryIncidentReporter` pass every subject
     * either observer runs and are empty again at the end of the request, so
     * durability is a question only the resolved identity can ask. Without that
     * pairing this change would have re-opened ADR-0041's defect while closing its
     * own gate, and `PseudonymizationProbe` and `BreachNotificationProbe` are
     * declared as their own classes rather than through `CapabilityProbe` so both
     * facts can be essential — a Partial verdict passes `compliance:check`, which
     * fails on Unsatisfied.
     *
     * THE REST OF gdpr MOVED LATER, AND NOT WITH THIS CHANGE. Art 5(1)(f) and Art
     * 32 declare PERSONAL DATA, and at the moment ADR-0065 landed this release
     * measured no cryptography over that estate — the session seal above
     * interrogates session payloads — so both stayed Unsatisfied with the finding
     * naming which estate WAS exercised. ADR-0066 then measured the estate itself
     * and both went green on their own fact, together with CCPA 1798.150, which is
     * declared over the same one. That is the sequence these figures record and
     * the reason `gdpr` reads 4 and `ccpa` reads 1 above. Nothing in `dora`,
     * `soc2`, `mdr`, `nist_csf` or
     * `swift_csp` moved either, though `IncidentResponseProbe` serves seven controls
     * whose estate the register measurement would pass: those ask for an incident
     * management PROCESS — detect, manage and notify — and a register that works
     * evidences one third of it. Whether that third is enough is a reading of seven
     * standards one at a time, and it is deferred rather than assumed; see that
     * probe's docblock.
     *
     * NOTHING ELSE IN ai_act MOVED, and that is deliberate rather than a limit
     * reached. `ai-act-art-50-1` and `ai-act-art-50-2` stay operator artefacts:
     * whether a person saw the notice and whether real output carried the mark
     * happen where no container can look, and grading either from a subsystem that
     * CAN produce them is the inflation this whole file exists to prevent. The
     * remaining twenty AI Act controls are obligations of conduct and of
     * documentation and stay operator responsibilities.
     *
     * SAY WHAT THIS COSTS, because it is the other half of the trade. TWENTY
     * controls can no longer reach Satisfied on ANY deployment shape this release
     * can build — more than the nineteen findings that moved here, because five of
     * them are stuck on shapes this fixture does not build. Two of those five are
     * HIPAA's ePHI encryption controls, which were satisfiable by the DATABASE LINK
     * having negotiated TLS: a false green that only appears on a deployment with a
     * networked database, and that nobody had noticed. Counted against the facts
     * this release can produce at grade Measured — five at that moment, and one
     * more with every observer written since — the controls that could in
     * principle be satisfied fell from 33 to 13 the day the estate join landed.
     * That is the low-water mark rather than the standing figure; each paragraph
     * above records one fact climbing back off it, and the count above is the
     * only number this test asserts.
     *
     * That is an instrument stuck in one direction, which is broken however
     * comfortable the direction is. It is recorded rather than traded quietly:
     * {@see \Pulsar\Tests\Unit\Compliance\Control\ControlSubjectReachabilityTest}
     * proves the stuck state by execution and names what would unstick it, which
     * is an observer that writes an audit event through the bound sink and reads
     * it back — the same shape as TokenVaultObserver, and ADR-0062's stated next
     * piece.
     *
     * SIX frameworks still satisfy nothing at all on an equipped deployment.
     * eIDAS is one of them, and that is the correct report: Pulsar observes no
     * signature being created or validated, so it has nothing to say about a
     * deployment's trust services beyond which classes are wired. A number that
     * said otherwise would be the thing this subsystem exists to stop. CCPA left
     * that list under ADR-0066 and HIPAA did not, which is the shape of what these
     * figures record: 1798.150 is declared over personal data and that estate is
     * now measured, while HIPAA's encryption controls are declared over health
     * data and nothing here has been through the estate they name.
     */
    #[Test]
    #[DataProvider('everyDeclaredFramework')]
    public function anEquippedDeploymentSatisfiesExactlyWhatItCanMeasure(ComplianceFramework $framework): void
    {
        $findings = self::assess($framework, self::equipped());

        $satisfied = count(array_filter(
            $findings,
            static fn(ControlFinding $finding): bool => $finding->outcome === ControlOutcome::Satisfied,
        ));

        $recorded = self::SATISFIED_WHEN_EQUIPPED[$framework->value] ?? null;

        self::assertNotNull(
            $recorded,
            sprintf(
                '%s declares controls and has no recorded figure. Run the assessment against an '
                    . 'equipped deployment and write down what it can observe.',
                $framework->value,
            ),
        );
        self::assertSame(
            $recorded,
            $satisfied,
            sprintf(
                '%s satisfies a different number of controls than the recorded one. If a probe '
                    . 'gained a real measurement, raise the figure; if one lost a measurement, '
                    . 'lower it and say why in the probe. Do not adjust it to make a report look '
                    . 'better.',
                $framework->value,
            ),
        );
    }

    /**
     * Every satisfied control on an equipped deployment carries a fact that was
     * EXERCISED — not a class name, not a config value, not an operator claim —
     * AND that interrogated the estate the control regulates.
     *
     * This is the property the count above is a consequence of, and it is the one
     * that must never be relaxed. Asserted over the whole catalogue so a probe
     * cannot be satisfied by something weaker in one framework than in another.
     *
     * The estate clause is the half added with {@see ControlSubject}, and it is
     * checked here rather than trusted from the decision table because the two
     * halves failed independently: `extension_loaded('sodium')` was a fact about
     * the wrong KIND of thing, and `audit_chain_verified` is a real measurement
     * about the wrong THING. A test that asked only the first would have passed
     * through the whole life of the second.
     */
    #[Test]
    public function everySatisfiedControlAnywhereRestsOnSomethingThatRanOnItsOwnEstate(): void
    {
        foreach (self::everyDeclaredFramework() as [$framework]) {
            foreach (self::assess($framework, self::equipped()) as $finding) {
                if ($finding->outcome !== ControlOutcome::Satisfied) {
                    continue;
                }

                $subject = $finding->declaration->assessedSubject();

                $measured = array_filter(
                    $finding->evidence,
                    static fn(Observation $observation): bool => $observation->isAdmissibleAsProof()
                        && $subject->covers($observation->id->subject()),
                );

                self::assertNotSame(
                    [],
                    $measured,
                    sprintf(
                        '%s/%s is Satisfied without one observation that was measured on %s.',
                        $framework->value,
                        $finding->declaration->id,
                        $subject->value,
                    ),
                );
            }
        }
    }

    /**
     * And every probed declaration names the estate it regulates.
     *
     * Guaranteed by the type — the parameter is required — so this asserts the
     * guarantee holds through the accessor the engine actually calls, and that no
     * checklist declaration acquired one by accident.
     */
    #[Test]
    public function everyProbedDeclarationNamesTheEstateItRegulates(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            if ($declaration->isProbed()) {
                self::assertInstanceOf(ControlSubject::class, $declaration->subject, $label);

                continue;
            }

            self::assertNull(
                $declaration->subject,
                sprintf('%s is never assessed against facts, so it must not name an estate.', $label),
            );
        }
    }

    /**
     * And the counter-assertion that keeps the two above honest: a probe that
     * always reports a gap would pass "nothing is satisfied on a bare deployment"
     * and would pass a per-framework figure of zero. Equipping the deployment must
     * move the total.
     */
    #[Test]
    public function equippingTheDeploymentMovesTheTotal(): void
    {
        self::assertSame(0, self::satisfiedAcrossEveryFramework(self::bare()));
        self::assertSame(
            array_sum(self::SATISFIED_WHEN_EQUIPPED),
            self::satisfiedAcrossEveryFramework(self::equipped()),
        );
        self::assertGreaterThan(0, array_sum(self::SATISFIED_WHEN_EQUIPPED));
    }

    private static function satisfiedAcrossEveryFramework(ControlEvidence $evidence): int
    {
        $satisfied = 0;

        foreach (self::everyDeclaredFramework() as [$framework]) {
            $satisfied += count(array_filter(
                self::assess($framework, $evidence),
                static fn(ControlFinding $finding): bool => $finding->outcome === ControlOutcome::Satisfied,
            ));
        }

        return $satisfied;
    }

    /**
     * Operator-responsibility controls never move, in either direction. They are
     * excluded from the coverage arithmetic and can neither fail the report nor
     * pad its percentage, which is what stops SOC 2's twenty-four organizational
     * criteria from being read as coverage.
     */
    #[Test]
    public function operatorResponsibilityControlsAreExcludedFromCoverageEverywhere(): void
    {
        $findings = [];

        foreach (self::everyDeclaredFramework() as [$framework]) {
            $findings = [...$findings, ...self::assess($framework, self::equipped())];
        }

        $checklist = array_filter(
            $findings,
            static fn(ControlFinding $finding): bool => !$finding->declaration->isProbed(),
        );

        self::assertNotSame([], $checklist);

        foreach ($checklist as $finding) {
            self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
            self::assertFalse($finding->outcome->countsTowardCoverage());
            self::assertFalse($finding->isFailing(strict: true));
        }
    }

    /**
     * Coverage is reported as two numbers, never one. A catalogue that is mostly
     * checklist must not read as mostly covered.
     */
    #[Test]
    public function coverageAndChecklistAreCountedSeparately(): void
    {
        $findings = self::assess(ComplianceFramework::Soc2, self::equipped());
        $summary = ControlAssessment::summarize($findings);

        self::assertGreaterThan(0, $summary->operatorChecklist);
        self::assertSame(
            count($findings) - $summary->operatorChecklist - $summary->notApplicable,
            $summary->assessed,
            'The checklist and the not-applicable controls must both leave the denominator.',
        );
        self::assertLessThanOrEqual(100.0, $summary->probedCoveragePercent());
    }

    /**
     * @return iterable<string, array{ComplianceFramework}>
     */
    public static function everyDeclaredFramework(): iterable
    {
        $catalog = new ControlCatalog();
        $catalog->register(...self::declarations());

        foreach ($catalog->frameworks() as $framework) {
            yield $framework->value => [$framework];
        }
    }

    /**
     * @return list<ControlFinding>
     */
    private static function assess(ComplianceFramework $framework, ControlEvidence $evidence): array
    {
        $catalog = new ControlCatalog();
        $catalog->register(...self::declarations());

        return new ControlAssessment($catalog)->assessFrameworks([$framework], $evidence);
    }

    private static ?ControlEvidence $bare = null;

    private static ?ControlEvidence $equipped = null;

    /**
     * Gathering opens connections and executes health checks, so both deployments
     * are observed once for the whole class rather than once per framework.
     */
    private static function bare(): ControlEvidence
    {
        return self::$bare ??= DeploymentUnderAssessment::withNothing(self::frameworkList())
            ->withoutComplianceProfile()
            ->evidence();
    }

    private static function equipped(): ControlEvidence
    {
        return self::$equipped ??= DeploymentUnderAssessment::fullyEquipped(self::frameworkList())
            ->evidence();
    }

    /**
     * @return list<ComplianceFramework>
     */
    private static function frameworkList(): array
    {
        $catalog = new ControlCatalog();
        $catalog->register(...self::declarations());

        return array_map(
            static fn(ComplianceFramework $framework): ComplianceFramework => $framework,
            $catalog->frameworks(),
        );
    }
}
