<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\AiModelRegistryProbe;
use Pulsar\Compliance\Probe\AiTransparencyProbe;

/**
 * Declares the EU AI Act controls Pulsar can be assessed against.
 *
 * THE DATES DECIDE THE SHAPE OF THIS FILE, so they come first.
 *
 * Regulation (EU) 2024/1689 did not arrive all at once, and the digital omnibus
 * in force since 27 July 2026 moved part of it again. What binds a deployment
 * today is a short list:
 *
 * - Article 4, AI literacy, since 2 February 2025.
 * - Article 5, prohibited practices, since 2 February 2025.
 * - Chapter V, general-purpose model providers, since 2 August 2025.
 * - Article 50, transparency, since 2 August 2026 — the omnibus left it alone.
 *
 * Chapter III, the whole high-risk regime, does not: the Annex III use cases now
 * apply from 2 December 2027 and the Annex I safety components from 2 August
 * 2028. Its controls are declared here anyway, because a deployment placing a
 * high-risk system on the market is building for that date now and an assessor
 * preparing for it needs the list. Each one says when it starts to bind. None of
 * them is graded as a present failure, because a duty that has not commenced
 * cannot be breached.
 *
 * WHAT IS PROBED, AND WHY SO LITTLE. Two controls carry a probe. Both observe
 * that a mechanism EXISTS, and both say so in their own requirement text rather
 * than letting a reader infer more. The Act's obligations are almost entirely
 * obligations of conduct and of documentation — do not engage in this practice,
 * hold this file, tell this person, register in that database — and a framework
 * observes none of those. The remaining controls therefore name an operator
 * artefact instead of a probe, which is the honest count, not a gap to be closed
 * later by grading configuration as compliance.
 *
 * Article 50 is the one place where a framework carries real weight, because the
 * duty is discharged in what a response contains. It is also the only control in
 * this file that a deployment can be OBSERVED discharging any part of:
 * ai-act-art-50-capability rests on the transparency subsystem having been
 * exercised — a surface declared, the policy read back owing both duties, a
 * synthetic-content mark minted and its machine-readable form checked. It used to
 * rest on the contract having resolved, which is a class name, and this mapping
 * therefore satisfied nothing on any deployment. See
 * {@see \Pulsar\Compliance\Evidence\AiTransparencyObserver}.
 *
 * The split around it is kept exactly where it was, and the count moved by ONE.
 * ai-act-art-50-1 and ai-act-art-50-2 remain operator artefacts, because whether
 * a person actually saw the notice and whether real output actually carried the
 * mark happen where Pulsar cannot look — and a framework that graded either from
 * the subsystem being able to produce them would be inflating the very number
 * this repair exists to make trustworthy.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class AiActMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-4',
                framework: ComplianceFramework::AiAct,
                title: 'AI literacy',
                requirement: 'Providers and deployers shall take measures to ensure, to their best '
                    . 'extent, a sufficient level of AI literacy of their staff and other persons '
                    . 'dealing with the operation and use of AI systems on their behalf, taking into '
                    . 'account their technical knowledge, experience, education and training and the '
                    . 'context the systems are to be used in (Article 4). Applies since 2 February 2025.',
                artefact: 'The training records for staff operating or using the AI systems, showing '
                    . 'the level reached and the context it was tailored to.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-5',
                framework: ComplianceFramework::AiAct,
                title: 'Prohibited AI practices',
                requirement: 'The placing on the market, putting into service or use of the AI '
                    . 'practices listed in Article 5 is prohibited outright — among them subliminal '
                    . 'or manipulative techniques, exploitation of vulnerabilities, social scoring, '
                    . 'untargeted scraping to build facial recognition databases, and emotion '
                    . 'inference in the workplace and in education institutions. Applies since '
                    . '2 February 2025. This is an obligation of conduct: no artefact makes a '
                    . 'prohibited practice permissible, and no probe observes that an organisation '
                    . 'has refrained from one.',
                artefact: 'The assessment of each AI system in service against the Article 5 list, '
                    . 'with the reasoning that places it outside every prohibited category.',
            ),

            ControlDeclaration::probed(
                id: 'ai-act-art-5-enforcement',
                framework: ComplianceFramework::AiAct,
                title: 'A model classified as a prohibited practice cannot reach production',
                requirement: 'Observes that a model registry is resolved. That registry refuses to '
                    . 'register a model into production status, and refuses to transition one there, '
                    . 'when it is classified AiModelRiskLevel::Unacceptable — the tier that '
                    . 'corresponds to Article 5. The refusal names no remedial artefact because '
                    . 'Article 5 bans the practice rather than conditioning it. What this control '
                    . 'establishes is that the mechanism is in place; whether every system in service '
                    . 'has been classified correctly is ai-act-art-5, and no code can decide it.',
                probe: new AiModelRegistryProbe(),
                subject: ControlSubject::AiSystemGovernance,
            ),

            ControlDeclaration::probed(
                id: 'ai-act-art-50-capability',
                framework: ComplianceFramework::AiAct,
                title: 'Article 50 positions can be declared and generated output marked',
                requirement: 'The transparency subsystem in service is EXERCISED, not merely '
                    . 'resolved: a surface is declared through it, the position is read back owing '
                    . 'both the Article 50(1) notice and the Article 50(2) marking, a '
                    . 'synthetic-content mark is minted, and its machine-readable form is checked '
                    . 'to carry the content kind, the model, the surface and the generation instant '
                    . 'it was minted for. A mark is also demanded for a surface that was never '
                    . 'declared, and must be refused — a mark that traces to no declared policy '
                    . 'asserts nothing an assessor can corroborate. Article 50 has applied since '
                    . '2 August 2026. This control does not establish that any person was informed '
                    . 'or that any output was marked; see ai-act-art-50-1 and ai-act-art-50-2.',
                probe: new AiTransparencyProbe(),
                subject: ControlSubject::AiSystemGovernance,
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-50-1',
                framework: ComplianceFramework::AiAct,
                title: 'Disclosure of interaction with an AI system',
                requirement: 'Providers shall ensure that AI systems intended to interact directly '
                    . 'with natural persons are designed and developed in such a way that the persons '
                    . 'concerned are informed that they are interacting with an AI system, unless this '
                    . 'is obvious from the point of view of a reasonably well-informed, observant and '
                    . 'circumspect natural person, taking into account the circumstances and context '
                    . 'of use. The information shall be provided in a clear and distinguishable manner '
                    . 'at the latest at the time of the first interaction or exposure (Article 50(1), '
                    . '50(5)). Applies since 2 August 2026.',
                artefact: 'For each surface that interacts with natural persons: the notice as it is '
                    . 'actually rendered, showing where in the flow the person meets it and that it '
                    . 'precedes the first interaction; or, where an exemption is relied on, the '
                    . 'justification the exemption requires.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-50-2',
                framework: ComplianceFramework::AiAct,
                title: 'Machine-readable marking of synthetic content',
                requirement: 'Providers of AI systems generating synthetic audio, image, video or text '
                    . 'content shall ensure the outputs are marked in a machine-readable format and '
                    . 'detectable as artificially generated or manipulated, with solutions that are '
                    . 'effective, interoperable, robust and reliable as far as this is technically '
                    . 'feasible (Article 50(2)). Applies since 2 August 2026. The framework marks at '
                    . 'the delivery boundary, which satisfies the machine-readable and detectable '
                    . 'limbs; it embeds nothing in a media signal, so the robustness limb for image, '
                    . 'audio and video is discharged by a provenance standard applied where the media '
                    . 'is produced.',
                artefact: 'A captured response for each generated output kind, showing the mark it '
                    . 'carried; and for image, audio or video, the provenance or watermarking scheme '
                    . 'applied at generation, with its coverage.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-50-3',
                framework: ComplianceFramework::AiAct,
                title: 'Notification of emotion recognition and biometric categorisation',
                requirement: 'Deployers of an emotion recognition system or a biometric categorisation '
                    . 'system shall inform the natural persons exposed to it of its operation, and '
                    . 'shall process the personal data in accordance with Regulations (EU) 2016/679 '
                    . 'and (EU) 2018/1725 and Directive (EU) 2016/680 (Article 50(3)). Applies since '
                    . '2 August 2026. Note that Article 5 already prohibits emotion inference in the '
                    . 'workplace and in education institutions outright, so this duty concerns only '
                    . 'deployments outside those settings.',
                artefact: 'The notification given to exposed persons, with the lawful basis and the '
                    . 'data protection impact assessment for the biometric processing.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-50-4',
                framework: ComplianceFramework::AiAct,
                title: 'Disclosure of deep fakes and of AI-generated public-interest text',
                requirement: 'Deployers generating or manipulating image, audio or video content '
                    . 'constituting a deep fake shall disclose that the content has been artificially '
                    . 'generated or manipulated. Deployers generating or manipulating text published '
                    . 'to inform the public on matters of public interest shall disclose that it is '
                    . 'artificially generated, unless the content underwent human review or editorial '
                    . 'control and a person holds editorial responsibility. Where the content is part '
                    . 'of an evidently artistic, creative, satirical or fictional work, disclosure is '
                    . 'limited to a manner that does not hamper the display or enjoyment of the work '
                    . '(Article 50(4)). Applies since 2 August 2026. This is a DEPLOYER duty and is '
                    . 'not discharged by the provider-side marking in ai-act-art-50-2.',
                artefact: 'The disclosure as published alongside the content, and for public-interest '
                    . 'text the record of human editorial review and of who holds responsibility.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-53',
                framework: ComplianceFramework::AiAct,
                title: 'Obligations of general-purpose AI model providers',
                requirement: 'Providers of general-purpose AI models shall draw up and keep up to date '
                    . 'the technical documentation of the model, make information available to '
                    . 'downstream providers, put in place a policy to comply with Union copyright law '
                    . 'including a reservation of rights under Article 4(3) of Directive (EU) '
                    . '2019/790, and publish a sufficiently detailed summary of the content used for '
                    . 'training (Article 53). Applies since 2 August 2025.',
                artefact: 'The model technical documentation, the downstream information package, the '
                    . 'copyright policy, and the published training-content summary.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-55',
                framework: ComplianceFramework::AiAct,
                title: 'Obligations of providers of models with systemic risk',
                requirement: 'Providers of general-purpose AI models with systemic risk shall perform '
                    . 'model evaluation including adversarial testing, assess and mitigate systemic '
                    . 'risks at Union level, track and report serious incidents to the AI Office and '
                    . 'national authorities without undue delay, and ensure an adequate level of '
                    . 'cybersecurity for the model and its physical infrastructure (Article 55). '
                    . 'Applies since 2 August 2025.',
                artefact: 'The model evaluation and adversarial testing reports, the systemic risk '
                    . 'assessment and mitigation record, the incident reports filed, and the '
                    . 'cybersecurity assessment of the model and its infrastructure.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-9',
                framework: ComplianceFramework::AiAct,
                title: 'Risk management system for high-risk systems',
                requirement: 'A risk management system shall be established, implemented, documented '
                    . 'and maintained across the whole lifecycle of a high-risk AI system, as a '
                    . 'continuous iterative process requiring regular systematic review and updating '
                    . '(Article 9). BINDS FROM 2 December 2027 for Annex III use cases and from '
                    . '2 August 2028 for Annex I safety components.',
                artefact: 'The documented risk management system, showing the identified risks, the '
                    . 'adopted measures, and the review history across the lifecycle.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-11',
                framework: ComplianceFramework::AiAct,
                title: 'Technical documentation for high-risk systems',
                requirement: 'The technical documentation of a high-risk AI system shall be drawn up '
                    . 'before it is placed on the market or put into service, kept up to date, and '
                    . 'contain at least the elements set out in Annex IV (Article 11). BINDS FROM '
                    . '2 December 2027 for Annex III use cases and from 2 August 2028 for Annex I '
                    . 'safety components.',
                artefact: 'The Annex IV technical documentation for each high-risk system, at the '
                    . 'version in force for the deployed release.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-12',
                framework: ComplianceFramework::AiAct,
                title: 'Automatic logging over the lifetime of a high-risk system',
                requirement: 'High-risk AI systems shall technically allow for the automatic recording '
                    . 'of events over their lifetime, ensuring a level of traceability appropriate to '
                    . 'the intended purpose (Article 12); providers shall keep those logs for at least '
                    . 'six months where the logs are under their control (Article 19). BINDS FROM '
                    . '2 December 2027 for Annex III use cases and from 2 August 2028 for Annex I '
                    . 'safety components.',
                artefact: 'The logging design showing which events are recorded and how traceability '
                    . 'is achieved, with the retention configuration for the log store.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-14',
                framework: ComplianceFramework::AiAct,
                title: 'Human oversight of high-risk systems',
                requirement: 'High-risk AI systems shall be designed and developed so they can be '
                    . 'effectively overseen by natural persons during the period in which they are in '
                    . 'use, including the ability to decide not to use the system, to disregard or '
                    . 'reverse its output, and to intervene or interrupt its operation (Article 14). '
                    . 'BINDS FROM 2 December 2027 for Annex III use cases and from 2 August 2028 for '
                    . 'Annex I safety components.',
                artefact: 'The oversight design, naming who exercises it, the controls available to '
                    . 'them, and the evidence that intervention and interruption work in practice.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-17',
                framework: ComplianceFramework::AiAct,
                title: 'Quality management system',
                requirement: 'Providers of high-risk AI systems shall put in place a quality '
                    . 'management system, documented in written policies, procedures and '
                    . 'instructions, covering at least the elements listed in Article 17. BINDS FROM '
                    . '2 December 2027 for Annex III use cases and from 2 August 2028 for Annex I '
                    . 'safety components.',
                artefact: 'The quality management system documentation, covering each element '
                    . 'Article 17 enumerates.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-26',
                framework: ComplianceFramework::AiAct,
                title: 'Deployer obligations for high-risk systems',
                requirement: 'Deployers shall use high-risk AI systems in accordance with the '
                    . 'instructions for use, assign human oversight to natural persons with the '
                    . 'necessary competence and authority, ensure input data is relevant and '
                    . 'sufficiently representative, monitor operation, and keep the automatically '
                    . 'generated logs for at least six months (Article 26). BINDS FROM 2 December '
                    . '2027 for Annex III use cases and from 2 August 2028 for Annex I safety '
                    . 'components.',
                artefact: 'The record of assigned oversight personnel and their competence, the input '
                    . 'data governance record, and the retained logs for the period.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-27',
                framework: ComplianceFramework::AiAct,
                title: 'Fundamental rights impact assessment',
                requirement: 'Deployers that are bodies governed by public law, private entities '
                    . 'providing public services, or deployers of the creditworthiness and life or '
                    . 'health insurance pricing use cases, shall perform an assessment of the impact '
                    . 'on fundamental rights that use of the system may produce, and notify the market '
                    . 'surveillance authority of its results (Article 27). BINDS FROM 2 December 2027.',
                artefact: 'The fundamental rights impact assessment and the notification filed with '
                    . 'the market surveillance authority.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-43',
                framework: ComplianceFramework::AiAct,
                title: 'Conformity assessment',
                requirement: 'High-risk AI systems shall undergo the applicable conformity assessment '
                    . 'procedure before being placed on the market or put into service, and a new '
                    . 'assessment whenever they are substantially modified (Article 43). BINDS FROM '
                    . '2 December 2027 for Annex III use cases and from 2 August 2028 for Annex I '
                    . 'safety components.',
                artefact: 'The conformity assessment record for the deployed version, with any '
                    . 'notified body certificate and the substantial-modification history.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-47',
                framework: ComplianceFramework::AiAct,
                title: 'EU declaration of conformity and CE marking',
                requirement: 'Providers shall draw up a written EU declaration of conformity for each '
                    . 'high-risk AI system, keep it at the disposal of the national authorities for '
                    . 'ten years, and affix the CE marking (Articles 47 and 48). BINDS FROM '
                    . '2 December 2027 for Annex III use cases and from 2 August 2028 for Annex I '
                    . 'safety components.',
                artefact: 'The signed EU declaration of conformity and the record of CE marking.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-49',
                framework: ComplianceFramework::AiAct,
                title: 'Registration in the EU database',
                requirement: 'Before placing on the market or putting into service a high-risk AI '
                    . 'system listed in Annex III, the provider or the authorised representative shall '
                    . 'register themselves and the system in the EU database referred to in '
                    . 'Article 71 (Article 49). BINDS FROM 2 December 2027.',
                artefact: 'The EU database registration entry for the provider and for each Annex III '
                    . 'system.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-72',
                framework: ComplianceFramework::AiAct,
                title: 'Post-market monitoring',
                requirement: 'Providers shall establish and document a post-market monitoring system '
                    . 'proportionate to the nature of the AI technologies and the risks of the '
                    . 'high-risk system, based on a post-market monitoring plan that forms part of the '
                    . 'Annex IV technical documentation (Article 72). BINDS FROM 2 December 2027 for '
                    . 'Annex III use cases and from 2 August 2028 for Annex I safety components.',
                artefact: 'The post-market monitoring plan and the data collected under it for the '
                    . 'period, including the analysis of performance in the field.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'ai-act-art-73',
                framework: ComplianceFramework::AiAct,
                title: 'Reporting of serious incidents',
                requirement: 'Providers shall report any serious incident to the market surveillance '
                    . 'authorities of the member state where it occurred, immediately after '
                    . 'establishing a causal link and in any event not later than fifteen days after '
                    . 'becoming aware of it; not later than two days in the case of a widespread '
                    . 'infringement or a serious and irreversible disruption of critical '
                    . 'infrastructure, and not later than ten days in the case of a death '
                    . '(Article 73). BINDS FROM 2 December 2027 for Annex III use cases and from '
                    . '2 August 2028 for Annex I safety components.',
                artefact: 'The serious incident register with the filing dates, showing each report '
                    . 'was made within the deadline applicable to its category.',
            ),
        ];
    }
}
