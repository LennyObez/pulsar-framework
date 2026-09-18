<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Probe\AccessRestrictionProbe;
use Pulsar\Compliance\Probe\AssetInventoryProbe;
use Pulsar\Compliance\Probe\HealthDataProtectionProbe;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;
use Pulsar\Compliance\Probe\TransportSecurityProbe;

/**
 * Declares the HIPAA Security Rule controls Pulsar can be assessed against,
 * including the 2026 rulemaking's tightened requirements.
 *
 * The encryption controls are gated on the operator's assertion about health
 * data: no code can know whether a deployment handles PHI, and the assertion is
 * reproduced in the report with its config key so an assessor can challenge it.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class HipaaMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: '164.312(a)(1)',
                framework: ComplianceFramework::Hipaa,
                title: 'Access Control',
                requirement: 'Implement technical policies and procedures for electronic information '
                    . 'systems that maintain electronic protected health information to allow '
                    . 'access only to those persons or software programs that have been granted '
                    . 'access rights.',
                probe: new AccessRestrictionProbe(),
                subject: ControlSubject::AccessControl,
            ),

            ControlDeclaration::probed(
                id: '164.312(a)(2)(iv)',
                framework: ComplianceFramework::Hipaa,
                title: 'Encryption and Decryption',
                requirement: 'Implement a mechanism to encrypt and decrypt electronic protected health '
                    . 'information.',
                probe: new HealthDataProtectionProbe(),
                subject: ControlSubject::HealthData,
            ),

            ControlDeclaration::probed(
                id: '164.312(b)',
                framework: ComplianceFramework::Hipaa,
                title: 'Audit Controls',
                requirement: 'Implement hardware, software, and procedural mechanisms that record and '
                    . 'examine activity in information systems that contain or use electronic '
                    . 'protected health information.',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),

            ControlDeclaration::probed(
                id: '164.312(c)(1)',
                framework: ComplianceFramework::Hipaa,
                title: 'Integrity',
                requirement: 'Implement policies and procedures to protect electronic protected health '
                    . 'information from improper alteration or destruction.',
                probe: new TamperEvidentAuditProbe(),
                subject: ControlSubject::AuditTrail,
            ),

            ControlDeclaration::probed(
                id: '164.312(e)(1)',
                framework: ComplianceFramework::Hipaa,
                title: 'Transmission Security',
                requirement: 'Implement technical security measures to guard against unauthorized '
                    . 'access to electronic protected health information that is being '
                    . 'transmitted over an electronic communications network.',
                probe: new TransportSecurityProbe(),
                subject: ControlSubject::DataInTransit,
            ),

            ControlDeclaration::probed(
                id: '164.312(d)-2026',
                framework: ComplianceFramework::Hipaa,
                title: 'Person or Entity Authentication (2026: MFA Required)',
                requirement: 'Implement procedures to verify that a person or entity seeking access to '
                    . 'electronic protected health information is the one claimed; the 2026 '
                    . 'rulemaking requires multi-factor authentication.',
                probe: new MultiFactorAuthenticationProbe(),
                subject: ControlSubject::Authentication,
            ),

            ControlDeclaration::probed(
                id: '164.312(a)(2)(iv)-2026',
                framework: ComplianceFramework::Hipaa,
                title: 'Encryption Mandatory (2026: At Rest AND In Transit)',
                requirement: 'The 2026 rulemaking makes encryption of electronic protected health '
                    . 'information mandatory both at rest and in transit, rather than '
                    . 'addressable.',
                probe: new HealthDataProtectionProbe(),
                subject: ControlSubject::HealthData,
            ),

            ControlDeclaration::probed(
                id: '164.308(a)(7)-2026',
                framework: ComplianceFramework::Hipaa,
                title: 'Contingency Plan (2026: 72-Hour Restoration)',
                requirement: 'Establish and implement procedures to restore any loss of data; the 2026 '
                    . 'rulemaking requires restoration of critical systems within 72 hours.',
                probe: new RecoveryCapabilityProbe(),
                subject: ControlSubject::BusinessContinuity,
            ),

            ControlDeclaration::probed(
                id: '164.312-2026-asset',
                framework: ComplianceFramework::Hipaa,
                title: 'Technology Asset Inventory (2026)',
                requirement: 'The 2026 rulemaking requires a written inventory of the technology '
                    . 'assets that create, receive, maintain or transmit electronic protected '
                    . 'health information, and a network map of how it moves.',
                probe: new AssetInventoryProbe(),
                subject: ControlSubject::RouteInventory,
            ),
        ];
    }
}
