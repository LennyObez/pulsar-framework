<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Control;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * The estate a control regulates, and the estate a fact interrogated.
 *
 * The vocabulary had no way to ask whether a measurement was ABOUT the thing a
 * control protects, and every remaining defect in the audit was a form of that
 * one question going unasked:
 *
 *  - `extension_loaded('sodium')` carried nine controls across seven frameworks.
 *    ADR-0061 answered the half of that about HOW the fact was obtained — a
 *    platform capability is not a measurement — and left the other half standing:
 *    even measured, an answer about the PHP build is not an answer about the
 *    personal data GDPR Art 5(1)(f) protects.
 *  - HIPAA 164.312(e)(1), Transmission Security, rested on the database link
 *    alone, so a deployment reaching its database over a unix socket RETIRED the
 *    control instead of failing it. The estate it regulates — data leaving the
 *    process — was never enumerated.
 *  - `scope.processes_personal_data = false` retired SOC 2 C1.2 and CC6.5, which
 *    are about CONFIDENTIAL INFORMATION. An entity that processes no personal
 *    data still holds contracts, pricing and source code, and still has to
 *    dispose of them.
 *
 * Two joins read this type, and they are stated on {@see ProbeVerdict::reach()}.
 * A fact may prove a control only if the control's estate COVERS the fact's; an
 * operator's scope assertion may retire a control only if the assertion's estate
 * covers the control's.
 *
 * WHERE THE ESTATE IS DECLARED, and why it is not on the probe. A control's
 * estate is named by the mapping author, on {@see ControlDeclaration::probed()},
 * against the standard's own text. A fact's estate is named by
 * {@see ObservationId::subject()}, so it travels with the fact's identity rather
 * than with whoever produced it. Putting the control's estate on the PROBE
 * instead would be self-certifying — the probe would declare the estate of its
 * own facts and then agree with itself — and it could not express the two cases
 * that made this type necessary: `DataErasureProbe` serves CCPA 1798.105, which
 * is about personal data, and SOC 2 C1.2, which is not; `TamperEvidentAuditProbe`
 * serves eleven controls about the audit trail while the only fact it measures
 * interrogates the compliance evidence register.
 *
 * THE OBJECTION THIS TYPE HAD TO ANSWER, recorded because it was a good one.
 * Review argued the general machinery was speculative: the three subject
 * mismatches anyone had actually found were SOC 2 C1.1, C1.2 and CC6.5, and one
 * unscoped copy of `DataErasureProbe` would have closed them, so the burden was
 * on the general solution to name a case the narrow fix would miss. Two answers,
 * and the first is a correction:
 *
 *  - C1.1 is not one of the three. It carries `AssetInventoryProbe`, which
 *    declares no scope assertion at all, so nothing was retiring it — verified by
 *    running the assessment with `scope.processes_personal_data = false` and
 *    reading which controls came back NotApplicable. The real list is two.
 *  - The case the probe split misses is `TamperEvidentAuditProbe`: eleven
 *    controls across eleven standards, all about the audit trail, all carried by
 *    a measurement of the compliance evidence register. No split closes that,
 *    because the estate the probe would declare is the estate of the facts it
 *    already requires — a probe naming its own subject agrees with itself by
 *    construction. Only a control declaring, from the standard's text, what it is
 *    about can disagree with the probe assigned to it.
 *
 * THE VOCABULARY IS DELIBERATELY NARROW, and being narrow is the whole security
 * property. An estate named one level too broadly re-opens the defect it was
 * introduced to close: `DataAtRest` would let "the session cipher resolved" answer
 * for cardholder data, which is `extension_loaded('sodium')` with a longer name.
 * So {@see SessionPayloads} is a case and "data at rest" is not, and
 * {@see CryptographicPlatform} — what the build offers — is kept apart from
 * {@see KeyHierarchy} — what this deployment derives with.
 *
 * FOR THE SAME REASON THE DATA CLASSES DO NOT NEST. Health data is personal data
 * and cardholder data usually is, and a containment relation saying so would have
 * been true and harmful: `scope.processes_personal_data = false` would then retire
 * HIPAA 164.312(a)(2)(iv) and PCI Req 3.4 as well, widening what one line of
 * config can silence. They are siblings here, so each assertion retires exactly
 * the controls whose estate it names.
 *
 * WHAT {@see covers()} IS FOR, and it is the one place this type is not flat.
 * A control about data in transit is proved by a fact about the database link,
 * and flat equality cannot say so. The relation is one level deep, every part is
 * justified by a control in this repository, and it is declared here rather than
 * inferred from the case names.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
enum ControlSubject: string
{
    // --- What the deployment handles -----------------------------------------
    // Siblings, never nested; see the class note. Each is the estate one operator
    // scope assertion in {@see \Pulsar\Compliance\Evidence\ComplianceScope} speaks
    // about, plus the one no assertion speaks about at all.

    /** The personal data the deployment processes, and the rights attached to it. */
    case PersonalData = 'personal_data';

    /** Protected health information. Asserted separately by the operator. */
    case HealthData = 'health_data';

    /** Primary account numbers and whatever stands in for them. */
    case CardholderData = 'cardholder_data';

    /**
     * Business information the entity designates confidential.
     *
     * NOT personal data, and the separation is the repair for SOC 2 C1.2 and
     * CC6.5. The Confidentiality criteria and the Privacy criteria are different
     * sections of the same standard, and an entity can be in scope for one and
     * out of scope for the other.
     */
    case ConfidentialInformation = 'confidential_information';

    // --- Where data sits, and where it moves ---------------------------------

    /** Session state carried between requests. Not "data at rest"; see the class note. */
    case SessionPayloads = 'session_payloads';

    /**
     * Data leaving this process, by whatever link carries it.
     *
     * The one estate with parts. HIPAA 164.312(e)(1) and SOC 2 CC6.7 regulate the
     * whole of it; the deployment has at least one link — it serves requests — so
     * the control cannot evaporate, and either part can prove it.
     */
    case DataInTransit = 'data_in_transit';

    /** The link to the database, when the deployment has one that crosses a network. */
    case DatabaseTransport = 'database_transport';

    /** The link that carries requests and responses. Every deployment that serves has one. */
    case HttpTransport = 'http_transport';

    /** The master key this deployment holds and everything derived from it. */
    case KeyHierarchy = 'key_hierarchy';

    /**
     * The cryptographic primitives the platform underneath the process offers.
     *
     * Kept apart from {@see KeyHierarchy} on purpose. A loaded extension and an
     * approved cipher suite are facts about the build; what this deployment
     * encrypts with is a different estate, and merging them is how nine controls
     * came to rest on `extension_loaded('sodium')`.
     */
    case CryptographicPlatform = 'cryptographic_platform';

    // --- The records the deployment keeps ------------------------------------

    /**
     * The record of activity the application writes through its audit sink.
     *
     * What ISO 27001 A.8.15, PCI Req 10.2, HIPAA 164.312(b) and eight more
     * regulate.
     */
    case AuditTrail = 'audit_trail';

    /**
     * The signed register of the framework's own compliance verification runs.
     *
     * A different estate from {@see AuditTrail} and the distinction is not
     * academic: `audit_chain_verified` recomputes HMACs over THIS register — the
     * subsystem's log of itself — and was carrying twelve controls about the
     * application's audit trail.
     */
    case ComplianceEvidenceRegister = 'compliance_evidence_register';

    // --- Subsystems ----------------------------------------------------------

    /** How a principal proves who it is. */
    case Authentication = 'authentication';

    /** Which principals may reach which entry points once identified. */
    case AccessControl = 'access_control';

    /** The entry points the router will dispatch, and how they are classified. */
    case RouteInventory = 'route_inventory';

    /** Detecting, reporting and notifying about incidents. */
    case IncidentResponse = 'incident_response';

    /** Backing the deployment up and bringing it back. */
    case BusinessContinuity = 'business_continuity';

    /** Whether the running deployment is healthy, and whether anyone is watching. */
    case OperationalMonitoring = 'operational_monitoring';

    /** The settings and features the deployment boots with. */
    case DeploymentConfiguration = 'deployment_configuration';

    /** The risk analysis and the governance posture behind it. */
    case RiskGovernance = 'risk_governance';

    /** The AI models, their assessments, their gates and their records. */
    case AiSystemGovernance = 'ai_system_governance';

    /**
     * Whether a fact about $part may speak for a control about this estate.
     *
     * True for the estate itself and for its declared parts, and false for
     * everything else — including the reverse direction, which matters: a fact
     * about the whole of {@see DataInTransit} would not prove a control about
     * {@see DatabaseTransport} alone, and nothing here lets it.
     *
     * The relation is enumerated rather than inferred. Case names look like a
     * hierarchy and are not one — {@see HealthData} reads like a part of
     * {@see PersonalData} and deliberately is not — so a rule derived from names
     * would silently widen what a scope assertion can retire.
     */
    #[NoDiscard]
    public function covers(ControlSubject $part): bool
    {
        return $part === $this || in_array($part, $this->parts(), true);
    }

    /**
     * The estates this one contains.
     *
     * Exhaustive, with no `default` arm: an estate added to this enum without an
     * answer here is an unhandled match at the point of use rather than a case
     * that quietly contains nothing. That is the shape ADR-0045 requires of every
     * total function over this vocabulary.
     *
     * Exactly one estate has parts today, and it earns them: HIPAA 164.312(e)(1)
     * and SOC 2 CC6.7 regulate data in transit, while the two facts that can speak
     * to it — the negotiated database session and the enforced HTTP transport
     * policy — each interrogate one link. Flat equality could not join those, and
     * the alternative was to name both facts "data in transit", which is the
     * too-broad naming this type exists to refuse.
     *
     * @return list<ControlSubject>
     */
    #[NoDiscard]
    private function parts(): array
    {
        return match ($this) {
            self::DataInTransit => [self::DatabaseTransport, self::HttpTransport],
            self::PersonalData,
            self::HealthData,
            self::CardholderData,
            self::ConfidentialInformation,
            self::SessionPayloads,
            self::DatabaseTransport,
            self::HttpTransport,
            self::KeyHierarchy,
            self::CryptographicPlatform,
            self::AuditTrail,
            self::ComplianceEvidenceRegister,
            self::Authentication,
            self::AccessControl,
            self::RouteInventory,
            self::IncidentResponse,
            self::BusinessContinuity,
            self::OperationalMonitoring,
            self::DeploymentConfiguration,
            self::RiskGovernance,
            self::AiSystemGovernance => [],
        };
    }
}
