<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\AccessRestrictionProbe;
use Pulsar\Compliance\Probe\BreachNotificationProbe;
use Pulsar\Compliance\Probe\CryptographicControlProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\RiskAssessmentProbe;

/**
 * Declares the NIS2 Article 21 and 23 controls Pulsar can be assessed against.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Nis2Mapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: 'NIS2-Art21(a)',
                framework: ComplianceFramework::Nis2,
                title: 'Risk Analysis and Information System Security Policies',
                requirement: 'Entities shall take measures covering policies on risk analysis and '
                    . 'information system security (Article 21(2)(a)).',
                probe: new RiskAssessmentProbe(),
                subject: ControlSubject::RiskGovernance,
            ),

            ControlDeclaration::probed(
                id: 'NIS2-Art21(b)',
                framework: ComplianceFramework::Nis2,
                title: 'Incident Handling',
                requirement: 'Entities shall take measures covering incident handling (Article '
                    . '21(2)(b)).',
                probe: new IncidentResponseProbe(),
                subject: ControlSubject::IncidentResponse,
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'NIS2-Art21(d)',
                framework: ComplianceFramework::Nis2,
                title: 'Supply Chain Security',
                requirement: 'Entities shall take measures covering supply chain security, including '
                    . 'security-related aspects of the relationships with direct suppliers or '
                    . 'service providers (Article 21(2)(d)).',
                artefact: 'The supply chain security assessments of the direct suppliers and '
                    . 'service providers, with the security requirements imposed on them.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'NIS2-Art21(e)',
                framework: ComplianceFramework::Nis2,
                title: 'Vulnerability Handling and Disclosure',
                requirement: 'Entities shall take measures covering security in network and '
                    . 'information systems acquisition, development and maintenance, including '
                    . 'vulnerability handling and disclosure (Article 21(2)(e)).',
                artefact: 'The coordinated vulnerability disclosure policy and the current advisory '
                    . 'log, with the remediation timelines met.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'NIS2-Art21(g)',
                framework: ComplianceFramework::Nis2,
                title: 'Basic Cyber Hygiene and Security Training',
                requirement: 'Entities shall take measures covering basic cyber hygiene practices and '
                    . 'cybersecurity training (Article 21(2)(g)).',
                artefact: 'The cyber hygiene practice documentation and the security training '
                    . 'records for the staff it binds, including the management body.',
            ),

            ControlDeclaration::probed(
                id: 'NIS2-Art21(h)',
                framework: ComplianceFramework::Nis2,
                title: 'Cryptography and Encryption',
                requirement: 'Entities shall take measures covering policies and procedures regarding '
                    . 'the use of cryptography and, where appropriate, encryption (Article '
                    . '21(2)(h)).',
                probe: new CryptographicControlProbe(),
                subject: ControlSubject::CryptographicPlatform,
            ),

            ControlDeclaration::probed(
                id: 'NIS2-Art21(i)',
                framework: ComplianceFramework::Nis2,
                title: 'Human Resources Security and Access Control',
                requirement: 'Entities shall take measures covering human resources security, access '
                    . 'control policies and asset management (Article 21(2)(i)).',
                probe: new AccessRestrictionProbe(),
                subject: ControlSubject::AccessControl,
            ),

            ControlDeclaration::probed(
                id: 'NIS2-Art23',
                framework: ComplianceFramework::Nis2,
                title: 'Incident Reporting Obligations',
                requirement: 'Entities shall notify the CSIRT or competent authority of any incident '
                    . 'having a significant impact, with an early warning within 24 hours and '
                    . 'an incident notification within 72 hours (Article 23).',
                probe: new BreachNotificationProbe(),
                subject: ControlSubject::IncidentResponse,
            ),
        ];
    }
}
