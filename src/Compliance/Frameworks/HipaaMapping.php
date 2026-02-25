<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers HIPAA Security Rule controls into the catalog.
 *
 * Maps Pulsar framework features to the HIPAA technical safeguard controls
 * they provide coverage for.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class HipaaMapping
{
    /**
     * Register HIPAA controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        $catalog->register(new Control(
            id: '164.312(a)(1)',
            framework: 'hipaa',
            title: 'Access Control',
            description: 'Implement technical policies and procedures for electronic information systems that '
                . 'maintain electronic protected health information to allow access only to those persons '
                . 'or software programs that have been granted access rights. Covered by authentication '
                . 'middleware and role-based access control.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['authentication', 'rbac', 'session_management'],
        ));

        $catalog->register(new Control(
            id: '164.312(a)(2)(iv)',
            framework: 'hipaa',
            title: 'Encryption and Decryption',
            description: 'Implement a mechanism to encrypt and decrypt electronic protected health information. '
                . 'Covered by the crypto/keyring subsystem, envelope encryption, and mail encryption '
                . 'capabilities.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'envelope_encryption', 'mail_encryption'],
        ));

        $catalog->register(new Control(
            id: '164.312(b)',
            framework: 'hipaa',
            title: 'Audit Controls',
            description: 'Implement hardware, software, and/or procedural mechanisms that record and examine '
                . 'activity in information systems that contain or use electronic protected health '
                . 'information. Covered by the audit logging subsystem with tamper-evident HMAC chains.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['audit_logging', 'hmac_chain', 'compliance_events'],
        ));

        $catalog->register(new Control(
            id: '164.312(c)(1)',
            framework: 'hipaa',
            title: 'Integrity',
            description: 'Implement policies and procedures to protect electronic protected health information '
                . 'from improper alteration or destruction. Covered by the integrity verification module '
                . 'and HMAC chain for audit trail tamper detection.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['integrity_verification', 'hmac_chain', 'data_classification'],
        ));

        $catalog->register(new Control(
            id: '164.312(e)(1)',
            framework: 'hipaa',
            title: 'Transmission Security',
            description: 'Implement technical security measures to guard against unauthorized access to '
                . 'electronic protected health information that is being transmitted over an electronic '
                . 'communications network. Covered by TLS enforcement and mail encryption policy.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['tls_enforcement', 'mail_encryption', 'csrf_protection'],
        ));
    }
}
