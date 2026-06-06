<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers PCI-DSS controls into the catalog.
 *
 * Maps Pulsar framework features to PCI-DSS requirements they provide coverage for.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class PciDssMapping
{
    /**
     * Register PCI-DSS controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: 'Req2.3',
            framework: 'pci_dss',
            title: 'Encrypt Non-Console Administrative Access',
            description: 'Encrypt all non-console administrative access using strong cryptography. '
                . 'Covered by TLS enforcement for all administrative endpoints and encrypted '
                . 'session management.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['tls_enforcement', 'session_encryption', 'csrf_protection'],
        ));

        $catalog->register(new Control(
            id: 'Req3.4',
            framework: 'pci_dss',
            title: 'Render PAN Unreadable Anywhere It Is Stored',
            description: 'Render PAN unreadable anywhere it is stored by using strong one-way hash functions, '
                . 'truncation, index tokens, or strong cryptography. Covered by TokenizationService '
                . '(format-preserving PAN tokenization with first-6/last-4 preservation), encrypted '
                . 'token vault via Encryptor (AES-256-GCM or XSalsa20-Poly1305), and DatabaseTokenStore '
                . 'for production persistence.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'envelope_encryption', 'tokenization', 'token_vault'],
        ));

        $catalog->register(new Control(
            id: 'Req6.5',
            framework: 'pci_dss',
            title: 'Address Common Coding Vulnerabilities',
            description: 'Address common coding vulnerabilities in software-development processes including '
                . 'injection flaws, buffer overflows, insecure cryptographic storage, and cross-site '
                . 'scripting. Covered by input validation, output escaping, and CSRF protection.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['input_validation', 'output_escaping', 'csrf_protection', 'sql_parameterization'],
        ));

        $catalog->register(new Control(
            id: 'Req8.2',
            framework: 'pci_dss',
            title: 'Unique Identification for All Users',
            description: 'Ensure proper user-authentication management for non-consumer users and '
                . 'administrators by assigning a unique identification. Covered by the authentication '
                . 'subsystem with multi-factor authentication support.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'mfa', 'session_management'],
        ));

        $catalog->register(new Control(
            id: 'Req10.2',
            framework: 'pci_dss',
            title: 'Automated Audit Trails',
            description: 'Implement automated audit trails for all system components to reconstruct events '
                . 'including user identification, event type, date/time, success/failure, origination, '
                . 'and identity/name of affected data. Covered by structured audit logging.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'compliance_events', 'hmac_chain'],
        ));
    }
}
