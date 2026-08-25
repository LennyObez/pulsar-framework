<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\AiAuditTrailProbe;
use Pulsar\Compliance\Probe\AiDataGovernanceProbe;
use Pulsar\Compliance\Probe\AiExplainabilityProbe;
use Pulsar\Compliance\Probe\AiImpactAssessmentProbe;
use Pulsar\Compliance\Probe\AiLifecycleProbe;
use Pulsar\Compliance\Probe\AiModelRegistryProbe;
use Pulsar\Compliance\Probe\AiMonitoringProbe;

/**
 * Declares the ISO/IEC 42001:2023 AI Management System controls Pulsar can be
 * assessed against.
 *
 * Every one of them depends on the ai-governance extension, so every probe here
 * requires that extension to be ACTIVE — installed, registered and booted — and
 * not merely available on disk. Thirteen of the fifteen used to be registered as
 * Implemented on the strength of interfaces existing in a package a deployment
 * might never have enabled.
 *
 * Three of them cite {@see \Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface},
 * which has ZERO implementations anywhere in this repository — the interface, the
 * lifecycle manager that references it, and one test double are all there is.
 * Clause 9.1, A.7 and 10.1 therefore cannot be satisfied on any deployment: at
 * best they report Partial with the missing hook named, and on a deployment
 * without the extension they report an outright gap. That is the same fact the old
 * catalogue reported as covered.
 *
 * Where the extension is not installed at all, the probes still report gaps rather
 * than NotApplicable: enabling Iso42001 in config/compliance.php IS the operator's
 * assertion that the deployment must satisfy it, so its absence is a gap and not a
 * scoping fact. Letting one config line silence a whole standard is precisely the
 * escape hatch this design exists to close.
 *
 * @see https://www.iso.org/standard/81230.html
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Iso42001Mapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            // --- Clause 6: Planning ---

            ControlDeclaration::probed(
                id: 'ISO42001-6.1.2',
                framework: ComplianceFramework::Iso42001,
                title: 'AI Risk Assessment',
                requirement: 'The organization shall define and apply an AI risk assessment process '
                    . 'that identifies risks to individuals, groups of individuals and societies.',
                probe: new AiImpactAssessmentProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-6.1.4',
                framework: ComplianceFramework::Iso42001,
                title: 'AI Risk Treatment',
                requirement: 'The organization shall define and apply an AI risk treatment process '
                    . 'to select appropriate risk treatment options and determine the controls '
                    . 'necessary to implement them.',
                probe: new AiLifecycleProbe(),
            ),

            // --- Clause 7: Support ---

            ControlDeclaration::probed(
                id: 'ISO42001-7.5',
                framework: ComplianceFramework::Iso42001,
                title: 'Documented Information',
                requirement: 'The AI management system shall include documented information '
                    . 'required by this document and determined by the organization as necessary '
                    . 'for its effectiveness.',
                probe: new AiModelRegistryProbe(),
            ),

            // --- Clause 8: Operation ---

            ControlDeclaration::probed(
                id: 'ISO42001-8.2',
                framework: ComplianceFramework::Iso42001,
                title: 'AI System Impact Assessment',
                requirement: 'The organization shall conduct AI system impact assessments at '
                    . 'appropriate stages of the AI system life cycle.',
                probe: new AiImpactAssessmentProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-8.3',
                framework: ComplianceFramework::Iso42001,
                title: 'Data for AI Systems',
                requirement: 'The organization shall define, document and implement processes for '
                    . 'managing data used in AI systems, including data provenance, quality and '
                    . 'the basis on which it was obtained.',
                probe: new AiDataGovernanceProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-8.4',
                framework: ComplianceFramework::Iso42001,
                title: 'AI System Life Cycle',
                requirement: 'The organization shall define and implement processes for the '
                    . 'responsible design, development, deployment, operation and retirement of AI '
                    . 'systems.',
                probe: new AiLifecycleProbe(),
            ),

            // --- Clause 9: Performance evaluation ---

            // The control the audit named. It was Implemented on the strength of
            // MonitoringHookInterface, an interface with no implementations, so the
            // catalogue asserted that a deployment monitored its models because a
            // file describing monitoring existed.
            ControlDeclaration::probed(
                id: 'ISO42001-9.1',
                framework: ComplianceFramework::Iso42001,
                title: 'Monitoring, Measurement, Analysis and Evaluation',
                requirement: 'The organization shall determine what needs to be monitored and '
                    . 'measured, the methods for monitoring, measurement, analysis and evaluation, '
                    . 'and when the results shall be analysed and evaluated.',
                probe: new AiMonitoringProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-9.2',
                framework: ComplianceFramework::Iso42001,
                title: 'Internal Audit',
                requirement: 'The organization shall conduct internal audits at planned intervals '
                    . 'to provide information on whether the AI management system conforms to '
                    . 'requirements and is effectively implemented and maintained.',
                probe: new AiAuditTrailProbe(),
            ),

            // --- Clause 10: Improvement ---

            ControlDeclaration::probed(
                id: 'ISO42001-10.1',
                framework: ComplianceFramework::Iso42001,
                title: 'Continual Improvement',
                requirement: 'The organization shall continually improve the suitability, adequacy '
                    . 'and effectiveness of the AI management system.',
                probe: new AiMonitoringProbe(),
            ),

            // --- Annex A reference controls ---

            // An AI policy is a document approved by management. The extension's
            // governance flags are settings that follow from such a policy; they
            // are not the policy, and grading the control from them is how A.2 came
            // to read Partial on the strength of four booleans.
            ControlDeclaration::operatorResponsibility(
                id: 'ISO42001-A.2',
                framework: ComplianceFramework::Iso42001,
                title: 'AI Policy',
                requirement: 'The organization shall document an AI policy, approved by top '
                    . 'management, and communicate it within the organization.',
                artefact: 'The approved AI policy, with its management approval record and '
                    . 'evidence of communication to the people it binds.',
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-A.5',
                framework: ComplianceFramework::Iso42001,
                title: 'Data for AI Systems',
                requirement: 'The organization shall define and implement controls for the data '
                    . 'used to develop and operate AI systems, covering provenance, quality, '
                    . 'preparation and the basis on which the data was obtained.',
                probe: new AiDataGovernanceProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-A.6',
                framework: ComplianceFramework::Iso42001,
                title: 'AI System Life Cycle',
                requirement: 'The organization shall define and implement controls for the '
                    . 'responsible development, deployment and retirement of AI systems.',
                probe: new AiLifecycleProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-A.7',
                framework: ComplianceFramework::Iso42001,
                title: 'AI System Operation and Monitoring',
                requirement: 'The organization shall define and implement controls for operating '
                    . 'AI systems and monitoring their behaviour in production.',
                probe: new AiMonitoringProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-A.8',
                framework: ComplianceFramework::Iso42001,
                title: 'Transparency and Explainability',
                requirement: 'The organization shall determine and document the level of '
                    . 'transparency and explainability appropriate to each AI system and to the '
                    . 'people affected by its decisions.',
                probe: new AiExplainabilityProbe(),
            ),

            ControlDeclaration::probed(
                id: 'ISO42001-A.10',
                framework: ComplianceFramework::Iso42001,
                title: 'AI System Documentation',
                requirement: 'The organization shall document the AI systems it develops or uses, '
                    . 'including their intended purpose, limitations and the assessments performed '
                    . 'on them.',
                probe: new AiModelRegistryProbe(),
            ),
        ];
    }
}
