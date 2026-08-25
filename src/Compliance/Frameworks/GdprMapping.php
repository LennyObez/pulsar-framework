<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Probe\BreachNotificationProbe;
use Pulsar\Compliance\Probe\CryptographicControlProbe;
use Pulsar\Compliance\Probe\DataProtectionAtRestProbe;
use Pulsar\Compliance\Probe\PseudonymizationProbe;

/**
 * Declares the GDPR controls Pulsar can be assessed against.
 */
#[Internal(reason: 'Framework-internal control declaration; the catalog is the public surface')]
final class GdprMapping
{
    /**
     * @return list<ControlDeclaration>
     */
    #[NoDiscard]
    public static function declarations(): array
    {
        return [
            ControlDeclaration::probed(
                id: 'Art5(1)(f)',
                framework: ComplianceFramework::Gdpr,
                title: 'Integrity and Confidentiality',
                requirement: 'Personal data shall be processed in a manner that ensures appropriate '
                    . 'security, including protection against unauthorised or unlawful '
                    . 'processing and against accidental loss, destruction or damage.',
                probe: new DataProtectionAtRestProbe(),
            ),

            ControlDeclaration::probed(
                id: 'Art25',
                framework: ComplianceFramework::Gdpr,
                title: 'Data Protection by Design and by Default',
                requirement: 'The controller shall implement appropriate technical and organisational '
                    . 'measures, such as pseudonymisation, designed to implement '
                    . 'data-protection principles and to integrate the necessary safeguards '
                    . 'into the processing.',
                probe: new PseudonymizationProbe(),
            ),

            ControlDeclaration::operatorResponsibility(
                id: 'Art30',
                framework: ComplianceFramework::Gdpr,
                title: 'Records of Processing Activities',
                requirement: 'Each controller shall maintain a record of processing activities under '
                    . 'its responsibility, in writing and available to the supervisory '
                    . 'authority on request.',
                artefact: 'The written record of processing activities, naming the purposes, the '
                    . 'categories of data subjects and personal data, the recipients, and the '
                    . 'retention periods.',
            ),

            ControlDeclaration::probed(
                id: 'Art32',
                framework: ComplianceFramework::Gdpr,
                title: 'Security of Processing',
                requirement: 'The controller and processor shall implement measures appropriate to the '
                    . 'risk, including the pseudonymisation and encryption of personal data and '
                    . 'the ability to ensure the ongoing confidentiality, integrity, '
                    . 'availability and resilience of processing systems.',
                probe: new CryptographicControlProbe(),
            ),

            ControlDeclaration::probed(
                id: 'Art33',
                framework: ComplianceFramework::Gdpr,
                title: 'Notification of a Personal Data Breach',
                requirement: 'The controller shall notify a personal data breach to the supervisory '
                    . 'authority without undue delay and, where feasible, not later than 72 '
                    . 'hours after having become aware of it.',
                probe: new BreachNotificationProbe(),
            ),
        ];
    }
}
