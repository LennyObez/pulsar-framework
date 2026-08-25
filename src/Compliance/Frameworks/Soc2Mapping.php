<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\AccessRestrictionProbe;
use Pulsar\Compliance\Probe\ApplicationHardeningProbe;
use Pulsar\Compliance\Probe\AssetInventoryProbe;
use Pulsar\Compliance\Probe\ConfigurationManagementProbe;
use Pulsar\Compliance\Probe\ConsentManagementProbe;
use Pulsar\Compliance\Probe\ContinuousMonitoringProbe;
use Pulsar\Compliance\Probe\DataErasureProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\PlatformHardeningProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Compliance\Probe\RiskAssessmentProbe;
use Pulsar\Compliance\Probe\TransportSecurityProbe;

/**
 * Declares the SOC 2 Trust Services Criteria Pulsar can be assessed against.
 *
 * Forty-two criteria, and the honest split is stark: CC1.x (control environment),
 * CC2.x (communication) and CC9.x (risk mitigation) are organizational by
 * definition, and every one of them used to carry a status derived from a Pulsar
 * feature — CC1.1, the commitment to integrity and ethical values, was graded from
 * the existence of audit logging. They are operator-responsibility declarations
 * now, each naming the artefact an assessor asks for, which is both truthful and
 * more use to that assessor than a tick beside a criterion no code can meet.
 *
 * Because operator-responsibility controls are excluded from the coverage
 * arithmetic, this framework's reported coverage will fall sharply. That is the
 * correction, not a regression: the previous figure counted criteria the framework
 * had never assessed.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Soc2Mapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::operatorResponsibility(
                id: 'CC1.1',
                framework: ComplianceFramework::Soc2,
                title: 'COSO Principle 1: Integrity and Ethical Values',
                requirement: 'The entity demonstrates a commitment to integrity and ethical values.',
                artefact: 'The code of conduct, its acknowledgement records, and the record of '
                    . 'deviations addressed.',
            ),

            ControlDeclaration::probed(
                id: 'CC6.1',
                framework: ComplianceFramework::Soc2,
                title: 'Logical and Physical Access Controls',
                requirement: 'The entity implements logical access security software, infrastructure '
                    . 'and architectures over protected information assets to protect them from '
                    . 'security events.',
                probe: new AccessRestrictionProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC6.3',
                framework: ComplianceFramework::Soc2,
                title: 'Role-Based Access Control',
                requirement: 'The entity authorizes, modifies or removes access to data, software, '
                    . 'functions and other protected information assets based on roles, '
                    . 'responsibilities or the system design and changes.',
                probe: new AccessRestrictionProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC7.2',
                framework: ComplianceFramework::Soc2,
                title: 'System Monitoring',
                requirement: 'The entity monitors system components and the operation of those '
                    . 'components for anomalies indicative of malicious acts, natural disasters '
                    . 'and errors.',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC8.1',
                framework: ComplianceFramework::Soc2,
                title: 'Change Management',
                requirement: 'The entity authorizes, designs, develops or acquires, configures, '
                    . 'documents, tests, approves and implements changes to infrastructure, '
                    . 'data, software and procedures to meet its objectives.',
                artefact: 'The change tickets for the deployed release, with their approvals, test '
                    . 'evidence and the CI run for the deployed commit.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC1.2',
                framework: ComplianceFramework::Soc2,
                title: 'Board of Directors Independence and Oversight',
                requirement: 'The board of directors demonstrates independence from management and '
                    . 'exercises oversight of the development and performance of internal '
                    . 'control.',
                artefact: 'The board charter, the minutes evidencing independent oversight, and the '
                    . 'membership record showing the required independence.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC1.3',
                framework: ComplianceFramework::Soc2,
                title: 'Management Responsibility for Internal Controls',
                requirement: 'Management establishes, with board oversight, structures, reporting '
                    . 'lines and appropriate authorities and responsibilities in the pursuit of '
                    . 'objectives.',
                artefact: 'The organisation chart, the documented authority and responsibility '
                    . 'assignments, and the delegation records.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC1.4',
                framework: ComplianceFramework::Soc2,
                title: 'Competence of Personnel',
                requirement: 'The entity demonstrates a commitment to attract, develop and retain '
                    . 'competent individuals in alignment with objectives.',
                artefact: 'The role competency definitions, the hiring records against them, and '
                    . 'the training completion records.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC1.5',
                framework: ComplianceFramework::Soc2,
                title: 'Accountability for Internal Controls',
                requirement: 'The entity holds individuals accountable for their internal control '
                    . 'responsibilities in the pursuit of objectives.',
                artefact: 'The performance objectives tied to internal-control responsibilities, '
                    . 'and the evaluations recorded against them.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC2.1',
                framework: ComplianceFramework::Soc2,
                title: 'Internal Information Quality',
                requirement: 'The entity obtains or generates and uses relevant, quality information '
                    . 'to support the functioning of internal control.',
                artefact: 'The definition of the information required for internal control and the '
                    . 'evidence that it is produced at the quality and frequency stated.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC2.2',
                framework: ComplianceFramework::Soc2,
                title: 'Internal Communication',
                requirement: 'The entity internally communicates information, including objectives and '
                    . 'responsibilities for internal control, necessary to support its '
                    . 'functioning.',
                artefact: 'The internal communication records covering control responsibilities, '
                    . 'and the channel through which deficiencies can be reported.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC2.3',
                framework: ComplianceFramework::Soc2,
                title: 'External Communication',
                requirement: 'The entity communicates with external parties regarding matters '
                    . 'affecting the functioning of internal control.',
                artefact: 'The external communication records, including the channel through which '
                    . 'external parties can report matters affecting internal control.',
            ),

            ControlDeclaration::probed(
                id: 'CC3.1',
                framework: ComplianceFramework::Soc2,
                title: 'Risk Identification',
                requirement: 'The entity specifies objectives with sufficient clarity to enable the '
                    . 'identification and assessment of risks relating to those objectives.',
                probe: new RiskAssessmentProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC3.2',
                framework: ComplianceFramework::Soc2,
                title: 'Risk Assessment for Fraud',
                requirement: 'The entity considers the potential for fraud in assessing risks to the '
                    . 'achievement of objectives.',
                artefact: 'The fraud risk assessment, naming the fraud scenarios considered and the '
                    . 'controls assigned to each.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC3.3',
                framework: ComplianceFramework::Soc2,
                title: 'Risk Assessment for Changes',
                requirement: 'The entity identifies and assesses changes that could significantly '
                    . 'impact the system of internal control.',
                artefact: 'The change risk assessments performed for significant changes, with the '
                    . 'approval records.',
            ),

            ControlDeclaration::probed(
                id: 'CC3.4',
                framework: ComplianceFramework::Soc2,
                title: 'Consideration of External Threats',
                requirement: 'The entity identifies and assesses changes in the external environment, '
                    . 'including threats, that could significantly impact the system of '
                    . 'internal control.',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC4.1',
                framework: ComplianceFramework::Soc2,
                title: 'Ongoing Monitoring',
                requirement: 'The entity selects, develops and performs ongoing or separate '
                    . 'evaluations to ascertain whether the components of internal control are '
                    . 'present and functioning.',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC4.2',
                framework: ComplianceFramework::Soc2,
                title: 'Evaluation and Communication of Deficiencies',
                requirement: 'The entity evaluates and communicates internal control deficiencies in a '
                    . 'timely manner to those parties responsible for taking corrective action.',
                artefact: 'The deficiency log, showing how each deficiency was evaluated, '
                    . 'communicated to the responsible party, and remediated.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC5.1',
                framework: ComplianceFramework::Soc2,
                title: 'Selection and Development of Controls',
                requirement: 'The entity selects and develops control activities that contribute to '
                    . 'the mitigation of risks to the achievement of objectives to acceptable '
                    . 'levels.',
                artefact: 'The control selection rationale linking each risk identified to the '
                    . 'control chosen to mitigate it.',
            ),

            ControlDeclaration::probed(
                id: 'CC5.2',
                framework: ComplianceFramework::Soc2,
                title: 'Technology Controls',
                requirement: 'The entity selects and develops general control activities over '
                    . 'technology to support the achievement of objectives.',
                probe: new ApplicationHardeningProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC5.3',
                framework: ComplianceFramework::Soc2,
                title: 'Deployment of Control Activities',
                requirement: 'The entity deploys control activities through policies that establish '
                    . 'what is expected and procedures that put those policies into action.',
                probe: new ConfigurationManagementProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC6.2',
                framework: ComplianceFramework::Soc2,
                title: 'Prior to Issuing System Credentials',
                requirement: 'Prior to issuing system credentials, the entity registers and authorizes '
                    . 'new internal and external users whose access is administered by the '
                    . 'entity.',
                artefact: 'The access request, approval and provisioning records showing identity '
                    . 'was verified before credentials were issued, and the deprovisioning '
                    . 'records on exit.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC6.4',
                framework: ComplianceFramework::Soc2,
                title: 'Restriction of Physical Access',
                requirement: 'The entity restricts physical access to facilities and protected '
                    . 'information assets to authorized personnel.',
                artefact: 'The physical access records for the facilities holding the information '
                    . 'assets, and the periodic review of who holds that access.',
            ),

            ControlDeclaration::probed(
                id: 'CC6.5',
                framework: ComplianceFramework::Soc2,
                title: 'Disposal of Confidential Information',
                requirement: 'The entity discontinues logical and physical protections over physical '
                    . 'assets only after the ability to read or recover data and software from '
                    . 'those assets has been diminished.',
                probe: new DataErasureProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC6.6',
                framework: ComplianceFramework::Soc2,
                title: 'Logical Access: External Threats',
                requirement: 'The entity implements logical access security measures to protect '
                    . 'against threats from sources outside its system boundaries.',
                probe: new PlatformHardeningProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC6.7',
                framework: ComplianceFramework::Soc2,
                title: 'Transmission Integrity',
                requirement: 'The entity restricts the transmission, movement and removal of '
                    . 'information to authorized users and processes, and protects it during '
                    . 'transmission, movement and removal.',
                probe: new TransportSecurityProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC6.8',
                framework: ComplianceFramework::Soc2,
                title: 'Preventing Unauthorized Software',
                requirement: 'The entity implements controls to prevent or detect and act upon the '
                    . 'introduction of unauthorized or malicious software.',
                artefact: 'The software allow-list or code-signing policy, with the verification '
                    . 'records for the software actually running.',
            ),

            ControlDeclaration::probed(
                id: 'CC7.1',
                framework: ComplianceFramework::Soc2,
                title: 'Anomaly Detection',
                requirement: 'The entity uses detection and monitoring procedures to identify changes '
                    . 'to configurations that result in the introduction of new '
                    . 'vulnerabilities, and susceptibilities to newly discovered '
                    . 'vulnerabilities.',
                probe: new ContinuousMonitoringProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC7.3',
                framework: ComplianceFramework::Soc2,
                title: 'Security Incident Response',
                requirement: 'The entity evaluates security events to determine whether they could or '
                    . 'have resulted in a failure to meet its objectives, and if so takes '
                    . 'action to prevent or address such failures.',
                probe: new IncidentResponseProbe(),
            ),

            ControlDeclaration::probed(
                id: 'CC7.4',
                framework: ComplianceFramework::Soc2,
                title: 'Recovery from Security Incidents',
                requirement: 'The entity responds to identified security incidents by executing a '
                    . 'defined incident response program to understand, contain, remediate and '
                    . 'communicate them.',
                // Understand, contain, remediate and COMMUNICATE. This was decided by
                // whether a backup and restore primitive is in service, which is A1.3's
                // subject and this one's neighbour; the incident reporter that records
                // what happened and starts the notification clock is the mechanism the
                // criterion actually names, and CC7.3 beside it already cites it.
                probe: new IncidentResponseProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC7.5',
                framework: ComplianceFramework::Soc2,
                title: 'Identification of Vulnerabilities',
                requirement: 'The entity identifies, develops and implements activities to recover '
                    . 'from identified security incidents.',
                artefact: 'The vulnerability scan and dependency audit reports for the deployed '
                    . 'commit, with the remediation record for each finding.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC9.1',
                framework: ComplianceFramework::Soc2,
                title: 'Risk Mitigation through Controls',
                requirement: 'The entity identifies, selects and develops risk mitigation activities '
                    . 'for risks arising from potential business disruptions.',
                artefact: 'The business continuity and disruption risk assessment, with the '
                    . 'mitigation plan it produced.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'CC9.2',
                framework: ComplianceFramework::Soc2,
                title: 'Vendor Risk Management',
                requirement: 'The entity assesses and manages risks associated with vendors and '
                    . 'business partners.',
                artefact: 'The vendor risk assessments and the contractual security commitments '
                    . 'obtained from each vendor and business partner.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'A1.1',
                framework: ComplianceFramework::Soc2,
                title: 'Availability Commitments and Objectives',
                requirement: 'The entity maintains, monitors and evaluates current processing capacity '
                    . 'and use of system components to manage capacity demand and to enable the '
                    . 'implementation of additional capacity.',
                artefact: 'The availability commitments made to customers and the capacity plan '
                    . 'supporting them, with the measurement of actual availability against '
                    . 'them.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'A1.2',
                framework: ComplianceFramework::Soc2,
                title: 'Environmental Protections',
                requirement: 'The entity authorizes, designs, develops, implements, operates, '
                    . 'approves, maintains and monitors environmental protections, software, '
                    . 'data backup processes and recovery infrastructure to meet its '
                    . 'objectives.',
                artefact: 'The environmental protection and redundancy arrangements of the hosting '
                    . 'facilities, and their most recent test records.',
            ),

            ControlDeclaration::probed(
                id: 'A1.3',
                framework: ComplianceFramework::Soc2,
                title: 'Recovery Procedures',
                requirement: 'The entity tests recovery plan procedures supporting system recovery to '
                    . 'meet its objectives.',
                probe: new RecoveryCapabilityProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'PI1.1',
                framework: ComplianceFramework::Soc2,
                title: 'Processing Integrity Policies',
                requirement: 'The entity obtains or generates, uses and communicates relevant, quality '
                    . 'information regarding the objectives related to processing to support '
                    . 'the use of products and services.',
                artefact: 'The documented processing integrity objectives and the specifications '
                    . 'the processing is measured against.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'PI1.2',
                framework: ComplianceFramework::Soc2,
                title: 'Accuracy and Completeness',
                requirement: 'The entity implements policies and procedures over system inputs, '
                    . 'including controls over completeness and accuracy, to result in products '
                    . 'and services that meet the entity\'s objectives.',
                artefact: 'The data-quality monitoring reports showing completeness and accuracy of '
                    . 'processed data, with the exceptions and their resolution.',
            ),

            ControlDeclaration::probed(
                id: 'C1.1',
                framework: ComplianceFramework::Soc2,
                title: 'Confidential Information Identification',
                requirement: 'The entity identifies and maintains confidential information to meet its '
                    . 'objectives related to confidentiality.',
                probe: new AssetInventoryProbe(),
            ),

            ControlDeclaration::probed(
                id: 'C1.2',
                framework: ComplianceFramework::Soc2,
                title: 'Confidential Information Disposal',
                requirement: 'The entity disposes of confidential information to meet its objectives '
                    . 'related to confidentiality.',
                probe: new DataErasureProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'P1.1',
                framework: ComplianceFramework::Soc2,
                title: 'Privacy Notice',
                requirement: 'The entity provides notice to data subjects about its privacy practices '
                    . 'to meet its objectives related to privacy.',
                artefact: 'The published privacy notice and the record of the version in force at '
                    . 'each point in time.',
            ),

            ControlDeclaration::probed(
                id: 'P1.2',
                framework: ComplianceFramework::Soc2,
                title: 'Choice and Consent',
                requirement: 'The entity communicates choices available regarding the collection, use, '
                    . 'retention, disclosure and disposal of personal information, and obtains '
                    . 'consent where required.',
                probe: new ConsentManagementProbe(),
            ),
        ];
    }
}
