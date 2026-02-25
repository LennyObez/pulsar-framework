<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers GDPR controls into the catalog.
 *
 * Maps Pulsar framework features to GDPR articles they provide coverage for.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class GdprMapping
{
    /**
     * Register GDPR controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'Art5(1)(f)',
            framework: 'gdpr',
            title: 'Integrity and Confidentiality',
            description: 'Personal data shall be processed in a manner that ensures appropriate security, '
                . 'including protection against unauthorized or unlawful processing and against accidental '
                . 'loss, destruction, or damage, using appropriate technical or organizational measures. '
                . 'Covered by encryption subsystems and access control.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'authorization', 'access_control'],
        ));

        $catalog->register(new Control(
            id: 'Art25',
            framework: 'gdpr',
            title: 'Data Protection by Design and by Default',
            description: 'The controller shall implement appropriate technical and organizational measures '
                . 'for ensuring that, by default, only personal data which are necessary for each specific '
                . 'purpose of the processing are processed. Covered by pseudonymization services and '
                . 'data classification.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['pseudonymization', 'data_classification', 'data_minimization'],
        ));

        $catalog->register(new Control(
            id: 'Art30',
            framework: 'gdpr',
            title: 'Records of Processing Activities',
            description: 'Each controller shall maintain a record of processing activities under its '
                . 'responsibility. Covered by comprehensive audit logging with structured compliance '
                . 'events for each data processing operation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'compliance_events', 'hmac_chain'],
        ));

        $catalog->register(new Control(
            id: 'Art32',
            framework: 'gdpr',
            title: 'Security of Processing',
            description: 'The controller and the processor shall implement appropriate technical and '
                . 'organizational measures to ensure a level of security appropriate to the risk, '
                . 'including encryption of personal data, ongoing confidentiality, and regular testing. '
                . 'Covered by cryptographic subsystems, access control, and system monitoring.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'access_control', 'observability', 'integrity_verification'],
        ));

        $catalog->register(new Control(
            id: 'Art33',
            framework: 'gdpr',
            title: 'Notification of a Personal Data Breach',
            description: 'In the case of a personal data breach, the controller shall without undue delay '
                . 'notify the personal data breach to the supervisory authority. Covered by compliance '
                . 'event infrastructure for breach detection and notification workflows.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['compliance_events', 'breach_detection', 'audit_logging'],
        ));
    }
}
