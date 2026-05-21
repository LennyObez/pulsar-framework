<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers CCPA/CPRA controls into the catalog.
 *
 * Maps Pulsar framework features to the California Consumer Privacy Act (CCPA)
 * and California Privacy Rights Act (CPRA) requirements they provide coverage for.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class CcpaMapping
{
    /**
     * Register CCPA/CPRA controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'CCPA-1798.100',
            framework: 'ccpa',
            title: 'Right to Know',
            description: 'Consumers have the right to know what personal information is collected, used, shared, '
                . 'or sold. Covered by the data classification system, audit logging of data access, '
                . 'and structured data export capabilities.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_classification', 'audit_logging', 'data_export'],
        ));

        $catalog->register(new Control(
            id: 'CCPA-1798.105',
            framework: 'ccpa',
            title: 'Right to Delete',
            description: 'Consumers have the right to request deletion of personal information collected from them. '
                . 'Covered by the data purge orchestrator, session purge, and audit log purge subsystems.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_purge', 'session_purge', 'audit_log_purge'],
        ));

        $catalog->register(new Control(
            id: 'CCPA-1798.120',
            framework: 'ccpa',
            title: 'Right to Opt-Out of Sale/Sharing',
            description: 'Consumers have the right to opt out of the sale or sharing of their personal information. '
                . 'Covered by the consent management subsystem with explicit opt-out tracking '
                . 'and consent withdrawal support.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['consent_management', 'consent_withdrawal', 'data_classification'],
        ));

        $catalog->register(new Control(
            id: 'CCPA-1798.140',
            framework: 'ccpa',
            title: 'Data Categories and Sensitive PI',
            description: 'CCPA/CPRA defines categories of personal information and sensitive personal information '
                . 'requiring enhanced protections. Covered by data classification and encryption '
                . 'subsystems that distinguish sensitivity levels.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_classification', 'crypto_keyring', 'envelope_encryption'],
        ));

        $catalog->register(new Control(
            id: 'CCPA-1798.150',
            framework: 'ccpa',
            title: 'Data Security (Safe Harbor)',
            description: 'CCPA provides a safe harbor for encrypted data: breaches of encrypted PI do not trigger '
                . 'private right of action if the encryption key is not compromised. Covered by the '
                . 'crypto keyring, envelope encryption, and tokenization subsystems.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'envelope_encryption', 'tokenization'],
        ));

        $catalog->register(new Control(
            id: 'CCPA-1798.185',
            framework: 'ccpa',
            title: 'CPRA Data Minimization',
            description: 'CPRA requires that collection, use, retention, and sharing of personal information '
                . 'be limited to what is reasonably necessary for the disclosed purpose. Covered by '
                . 'data retention policies and the purge orchestrator.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['data_retention', 'data_purge', 'consent_management'],
        ));
    }
}
