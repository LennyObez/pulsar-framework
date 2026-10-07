<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\AccessRestrictionProbe;
use Pulsar\Compliance\Probe\ConfigurationManagementProbe;
use Pulsar\Compliance\Probe\DataLeakagePreventionProbe;
use Pulsar\Compliance\Probe\KeyManagementProbe;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;

/**
 * Declares the ISO/IEC 27001:2022 Annex A controls Pulsar can be assessed against.
 *
 * Nine of these ten used to be registered as Implemented, and two of the nine
 * were statements no software can make. A.5.1 was graded from the existence of a
 * CSP config; A.8.25 from the existence of a PHPStan configuration file. Both are
 * now operator-responsibility declarations naming the artefact an assessor should
 * be shown, which is both honest and more use to that assessor than a green tick
 * beside a control the framework never touched.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class Iso27001Mapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            // --- A.5.x Organizational controls ---

            // An approved, published, communicated and reviewed policy SET. The
            // framework's CSP, CORS and rate-limit settings are not that document
            // and never were; grading this from their presence is the exact
            // substitution ADR-0041 warned about, one level up from a class.
            ControlDeclaration::operatorResponsibility(
                id: 'A.5.1',
                framework: ComplianceFramework::Iso27001,
                title: 'Policies for Information Security',
                requirement: 'Information security policy and topic-specific policies shall be '
                    . 'defined, approved by management, published, communicated to and '
                    . 'acknowledged by relevant personnel and interested parties, and reviewed at '
                    . 'planned intervals.',
                artefact: 'The approved information security policy set, with its management '
                    . 'approval record, publication date, acknowledgement register and the date '
                    . 'of the last review.',
            ),

            // --- A.8.x Technological controls ---

            // A property of the DEVICES, and Pulsar has never seen one. The control
            // covers what information may sit on an endpoint, its registration,
            // physical protection, malware protection, restrictions on installed
            // software, and remote wipe. It was probed by the session cipher and the
            // cookie flags — real facts about a browser session, and facts about a
            // different subject. A hardened cookie says nothing about the laptop it
            // is stored on.
            ControlDeclaration::operatorResponsibility(
                id: 'A.8.1',
                framework: ComplianceFramework::Iso27001,
                title: 'User Endpoint Devices',
                requirement: 'Information stored on, processed by or accessible via user endpoint '
                    . 'devices shall be protected.',
                artefact: 'The endpoint device policy with the register of enrolled devices, and '
                    . 'evidence of the protections it requires in force on them — disk '
                    . 'encryption, malware protection, screen lock, and the remote wipe path.',
            ),

            ControlDeclaration::probed(
                id: 'A.8.3',
                framework: ComplianceFramework::Iso27001,
                title: 'Information Access Restriction',
                requirement: 'Access to information and other associated assets shall be '
                    . 'restricted in accordance with the established topic-specific policy on '
                    . 'access control.',
                probe: new AccessRestrictionProbe(),
                subject: ControlSubject::AccessControl,
            ),

            ControlDeclaration::probed(
                id: 'A.8.5',
                framework: ComplianceFramework::Iso27001,
                title: 'Secure Authentication',
                requirement: 'Secure authentication technologies and procedures shall be '
                    . 'implemented based on information access restrictions and the '
                    . 'topic-specific policy on access control.',
                probe: new MultiFactorAuthenticationProbe(),
                subject: ControlSubject::Authentication,
            ),

            ControlDeclaration::probed(
                id: 'A.8.9',
                framework: ComplianceFramework::Iso27001,
                title: 'Configuration Management',
                requirement: 'Configurations, including security configurations, of hardware, '
                    . 'software, services and networks shall be established, documented, '
                    . 'implemented, monitored and reviewed.',
                probe: new ConfigurationManagementProbe(),
                subject: ControlSubject::DeploymentConfiguration,
            ),

            ControlDeclaration::probed(
                id: 'A.8.12',
                framework: ComplianceFramework::Iso27001,
                title: 'Data Leakage Prevention',
                requirement: 'Data leakage prevention measures shall be applied to systems, '
                    . 'networks and any other devices that process, store or transmit sensitive '
                    . 'information.',
                probe: new DataLeakagePreventionProbe(),
                subject: ControlSubject::ConfidentialInformation,
            ),

            ControlDeclaration::probed(
                id: 'A.8.15',
                framework: ComplianceFramework::Iso27001,
                title: 'Logging',
                requirement: 'Logs that record activities, exceptions, faults and other relevant '
                    . 'events shall be produced, stored, protected and analysed.',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),

            // "Including cryptographic key management" — and it was probed for
            // whether libsodium is loaded and a MasterKey object exists. Neither is
            // key management: an object that exists proves a constructor ran. The
            // probe is now the one that runs the KDF against the key in service and
            // checks it reproduces and separates domains. The requirement text
            // carries the residual, because the report prints it and that is where
            // a reader looks: software can answer "implemented", and the "defined"
            // half of this control is a document.
            ControlDeclaration::probed(
                id: 'A.8.24',
                framework: ComplianceFramework::Iso27001,
                title: 'Use of Cryptography',
                requirement: 'Rules for the effective use of cryptography, including cryptographic '
                    . 'key management, shall be defined and implemented. ASSESSED NARROWLY: the '
                    . 'implemented half only, and within it the derivation of the key hierarchy '
                    . 'this deployment encrypts under — that the KDF returns a subkey of the '
                    . 'requested length, reproduces it, and yields different material for a '
                    . 'different context. The rules themselves, and the key lifecycle from '
                    . 'generation through rotation to destruction, are documents; A.5.1 carries '
                    . 'them and is an operator responsibility.',
                probe: new KeyManagementProbe(),
                subject: ControlSubject::KeyHierarchy,
            ),

            // A property of how the software was BUILT. The static analysers, the
            // boundary checker and the test suite all ran somewhere else, at some
            // other time; the running application cannot be asked whether they
            // passed for the commit it is serving.
            ControlDeclaration::operatorResponsibility(
                id: 'A.8.25',
                framework: ComplianceFramework::Iso27001,
                title: 'Secure Development Life Cycle',
                requirement: 'Rules for the secure development of software and systems shall be '
                    . 'established and applied.',
                artefact: 'The documented secure development rules, plus a CI run showing the '
                    . 'full quality gate green for the commit currently deployed.',
            ),

            // Identified, specified and APPROVED — an act performed by people
            // before an application is built or bought, and a sibling of A.8.25
            // above. It was decided by whether any security feature had been left
            // bound-but-inert by a missing optional binding: a genuinely useful
            // observation, and one about the wiring of this release rather than
            // about anybody's requirements process.
            ControlDeclaration::operatorResponsibility(
                id: 'A.8.26',
                framework: ComplianceFramework::Iso27001,
                title: 'Application Security Requirements',
                requirement: 'Information security requirements shall be identified, specified and '
                    . 'approved when developing or acquiring applications.',
                artefact: 'The approved security requirements for this application, with the '
                    . 'record of who approved them and when, and — for acquired components — the '
                    . 'requirements set against the supplier.',
            ),
        ];
    }
}
