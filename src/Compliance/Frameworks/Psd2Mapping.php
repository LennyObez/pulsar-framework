<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers PSD2 controls into the catalog.
 *
 * Maps Pulsar framework features to PSD2 (Payment Services Directive 2) requirements
 * they provide coverage for, focusing on SCA, transaction monitoring, and open banking.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class Psd2Mapping
{
    /**
     * Register PSD2 controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'Art97.1',
            framework: 'psd2',
            title: 'Strong Customer Authentication',
            description: 'Payment service providers shall apply strong customer authentication where the '
                . 'payer initiates an electronic payment transaction. Covered by SCA middleware, '
                . 'challenge generation, and 2FA integration.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['sca_enforcement', 'mfa', 'step_up_auth'],
        ));

        $catalog->register(new Control(
            id: 'Art97.2',
            framework: 'psd2',
            title: 'SCA Dynamic Linking',
            description: 'The authentication code shall be dynamically linked to the amount and the payee. '
                . 'Covered by ScaDynamicLinkingService which binds authentication codes '
                . 'to transaction amount, currency, and payee identity.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['sca_dynamic_linking', 'challenge_verification'],
        ));

        $catalog->register(new Control(
            id: 'RTS.Art16',
            framework: 'psd2',
            title: 'Low-Value Transaction Exemption',
            description: 'SCA exemption for contactless electronic payments below EUR 50 and remote '
                . 'electronic payments below EUR 30. Covered by TransactionRiskAnalyzer with '
                . 'configurable low-value threshold.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['sca_exemptions', 'transaction_risk_analysis'],
        ));

        $catalog->register(new Control(
            id: 'RTS.Art18',
            framework: 'psd2',
            title: 'Transaction Risk Analysis',
            description: 'Real-time risk analysis of transactions using fraud rates, velocity checks, '
                . 'and anomaly detection. Covered by TransactionRiskAnalyzer with velocity '
                . 'tracking and configurable risk thresholds.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['transaction_risk_analysis', 'velocity_tracking', 'fraud_scoring'],
        ));

        $catalog->register(new Control(
            id: 'Art66',
            framework: 'psd2',
            title: 'Account Information Service Provider Access',
            description: 'Secure access for AISPs using eIDAS certificates. Covered by '
                . 'CertificateAuthenticationMiddleware with QWAC/QSEAL validation.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['certificate_authentication', 'eidas_certificates'],
        ));

        $catalog->register(new Control(
            id: 'Art67',
            framework: 'psd2',
            title: 'Payment Initiation Service Provider Access',
            description: 'Secure access for PISPs using eIDAS certificates and SCA. Covered by '
                . 'CertificateAuthenticationMiddleware and SCA enforcement middleware.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['certificate_authentication', 'sca_enforcement', 'eidas_certificates'],
        ));

        $catalog->register(new Control(
            id: 'Art94',
            framework: 'psd2',
            title: 'Audit Trail for Payment Transactions',
            description: 'Complete audit trail for all payment transactions and authentication events. '
                . 'Covered by compliance events (ScaChallengeCreated, ScaChallengeVerified, '
                . 'TransactionRiskAssessed, CertificateValidated) and AuditLogger integration.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'compliance_events', 'psd2_events'],
        ));
    }
}
