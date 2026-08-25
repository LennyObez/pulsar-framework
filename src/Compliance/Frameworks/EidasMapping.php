<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\MultiFactorAuthenticationProbe;
use Pulsar\Compliance\Probe\RiskAssessmentProbe;

/**
 * Declares the eIDAS controls Pulsar can be assessed against.
 *
 * Qualified status is granted by a supervisory body on the strength of a
 * conformity assessment; no amount of code can confer it, so the controls that
 * turn on it name the certificate and the assessment report instead of claiming
 * coverage.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class EidasMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: 'eIDAS-Art8',
                framework: ComplianceFramework::Eidas,
                title: 'Assurance Levels for Electronic Identification',
                requirement: 'An electronic identification scheme shall specify assurance levels low, '
                    . 'substantial or high for the electronic identification means it issues '
                    . '(Article 8).',
                probe: new MultiFactorAuthenticationProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'eIDAS-Art25-34',
                framework: ComplianceFramework::Eidas,
                title: 'Electronic Signatures',
                requirement: 'An electronic signature shall not be denied legal effect solely because '
                    . 'it is in electronic form; a qualified electronic signature has the '
                    . 'equivalent legal effect of a handwritten signature (Articles 25 to 34).',
                artefact: 'The qualified certificate for electronic signature and the conformity '
                    . 'assessment report of the trust service provider that issued it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'eIDAS-Art35-40',
                framework: ComplianceFramework::Eidas,
                title: 'Electronic Seals',
                requirement: 'An electronic seal shall enjoy the presumption of integrity of the data '
                    . 'and of correctness of the origin of that data to which it is linked '
                    . '(Articles 35 to 40).',
                artefact: 'The qualified certificate for electronic seal and the conformity '
                    . 'assessment report of the trust service provider that issued it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'eIDAS-Art41-42',
                framework: ComplianceFramework::Eidas,
                title: 'Electronic Time Stamps',
                requirement: 'A qualified electronic time stamp shall enjoy the presumption of '
                    . 'accuracy of the date and time it indicates and of the integrity of the '
                    . 'data linked to it (Articles 41 and 42).',
                artefact: 'The qualified electronic time stamp service agreement and the time '
                    . 'source traceability record of the provider.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'eIDAS-Art43-44',
                framework: ComplianceFramework::Eidas,
                title: 'Electronic Registered Delivery Services',
                requirement: 'Data sent and received using a qualified electronic registered delivery '
                    . 'service shall enjoy the presumption of integrity, of sending by the '
                    . 'identified sender and of receipt by the identified addressee (Articles '
                    . '43 and 44).',
                artefact: 'The qualified electronic registered delivery service agreement and its '
                    . 'conformity assessment report.',
            ),

            ControlDeclaration::probed(
                id: 'eIDAS-Art19',
                framework: ComplianceFramework::Eidas,
                title: 'Security Requirements for Trust Service Providers',
                requirement: 'Trust service providers shall take appropriate technical and '
                    . 'organisational measures to manage the risks posed to the security of the '
                    . 'trust services they provide (Article 19).',
                probe: new RiskAssessmentProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'eIDAS-Art24',
                framework: ComplianceFramework::Eidas,
                title: 'Requirements for Qualified Trust Service Providers',
                requirement: 'A qualified trust service provider shall verify the identity of the '
                    . 'person to whom a qualified certificate is issued and meet the '
                    . 'requirements for qualified status (Article 24).',
                artefact: 'The conformity assessment body report on which qualified status was '
                    . 'granted, and the supervisory body notification confirming it.',
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'eIDAS-Art17',
                framework: ComplianceFramework::Eidas,
                title: 'Electronic Identification Mutual Recognition',
                requirement: 'Electronic identification means issued under a notified scheme shall be '
                    . 'recognised in other member states for cross-border authentication '
                    . '(Article 17).',
                artefact: 'The notification of the electronic identification scheme to the '
                    . 'Commission and the peer review outcome for it.',
            ),
        ];
    }
}
