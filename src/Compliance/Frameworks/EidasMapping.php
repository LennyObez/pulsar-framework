<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Frameworks;

use Pulsar\Api\Internal;
use Pulsar\Compliance\Control;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\ControlStatus;

/**
 * Registers eIDAS Regulation (EU No 910/2014) controls into the catalog.
 *
 * Maps Pulsar framework features to eIDAS requirements for electronic
 * identification, authentication, and trust services including electronic
 * signatures, seals, timestamps, and registered delivery.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal(reason: 'Framework-internal control registration; use ControlCatalog for public access')]
final class EidasMapping
{
    /**
     * Register eIDAS controls into the given catalog.
     */
    public static function register(ControlCatalog $catalog): void
    {
        // --- Article 8: Assurance Levels ---

        $catalog->register(new Control(
            id: 'eIDAS-Art8',
            framework: 'eidas',
            title: 'Assurance Levels for Electronic Identification',
            description: 'Electronic identification schemes shall specify assurance levels low, '
                . 'substantial, or high. Covered by the LevelOfAssurance enum mapping to '
                . 'authentication guard configurations: Low (single-factor), Substantial '
                . '(multi-factor with TOTP), High (hardware-backed or qualified certificate). '
                . 'LevelOfAssuranceMiddleware enforces minimum LoA per route.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['level_of_assurance', 'authentication', 'mfa', 'loa_middleware'],
        ));

        // --- Articles 25-34: Electronic Signatures ---

        $catalog->register(new Control(
            id: 'eIDAS-Art25-34',
            framework: 'eidas',
            title: 'Electronic Signatures',
            description: 'Electronic signatures shall not be denied legal effect solely on the grounds '
                . 'that they are in electronic form. Covered by the DigitalSignatureServiceInterface '
                . 'supporting XAdES, PAdES, CAdES, and JAdES formats. SignatureInfo provides '
                . 'verification results including QES (Qualified Electronic Signature) status, '
                . 'trust chain validation, and signer identity.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['digital_signature', 'signature_formats', 'trust_chain', 'qes_support'],
        ));

        // --- Articles 35-40: Electronic Seals ---

        $catalog->register(new Control(
            id: 'eIDAS-Art35-40',
            framework: 'eidas',
            title: 'Electronic Seals',
            description: 'Electronic seals serve as evidence of origin and integrity of documents '
                . 'issued by legal persons. Covered by the ElectronicSealServiceInterface for '
                . 'organization-level sealing with format support (XAdES, PAdES, CAdES, JAdES) '
                . 'and seal verification with origin metadata.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['electronic_seal', 'signature_formats', 'integrity_verification'],
        ));

        // --- Articles 41-42: Qualified Timestamps ---

        $catalog->register(new Control(
            id: 'eIDAS-Art41-42',
            framework: 'eidas',
            title: 'Electronic Time Stamps',
            description: 'A qualified electronic time stamp shall enjoy the presumption of the accuracy '
                . 'of the date and time it indicates. Covered by the TimestampServiceInterface '
                . 'implementing RFC 3161 Time-Stamp Protocol with TSA integration, hash-based '
                . 'data binding, and timestamp verification. Integrates with the existing '
                . 'AuditEntry system for qualified timestamping of audit records.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['timestamp_service', 'rfc3161', 'audit_logging', 'integrity_verification'],
        ));

        // --- Articles 43-44: Electronic Registered Delivery ---

        $catalog->register(new Control(
            id: 'eIDAS-Art43-44',
            framework: 'eidas',
            title: 'Electronic Registered Delivery Services',
            description: 'Registered delivery shall provide evidence of transmission and receipt of '
                . 'data, protecting against risk of loss, theft, damage, or alteration. '
                . 'Covered by the RegisteredDeliveryServiceInterface with delivery receipts, '
                . 'content hashing for integrity, and non-repudiation evidence.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['registered_delivery', 'delivery_receipt', 'non_repudiation', 'content_integrity'],
        ));

        // --- Article 19: Security Requirements for TSPs ---

        $catalog->register(new Control(
            id: 'eIDAS-Art19',
            framework: 'eidas',
            title: 'Security Requirements for Trust Service Providers',
            description: 'Trust service providers shall take appropriate technical and organisational '
                . 'measures to manage risks to the security of trust services. Covered by the '
                . 'pluggable cipher suite architecture (AES-256-GCM, XSalsa20-Poly1305), '
                . 'FIPS 140-2 compatible mode, master key derivation with domain separation, '
                . 'and incident reporting via the IncidentReporter subsystem.',
            status: ControlStatus::Implemented,
            frameworkFeatures: ['crypto_keyring', 'cipher_suite', 'fips_validator', 'incident_reporter'],
        ));

        // --- Article 24: Qualified Trust Service Providers ---

        $catalog->register(new Control(
            id: 'eIDAS-Art24',
            framework: 'eidas',
            title: 'Requirements for Qualified Trust Service Providers',
            description: 'Qualified TSPs shall employ staff with necessary expertise and use '
                . 'trustworthy systems and products. Framework provides deploy checks, health '
                . 'check infrastructure (FipsComplianceCheck), audit logging with HMAC chain '
                . 'tamper detection, and structured compliance reporting. Organizational '
                . 'requirements (staff, premises) are the deployer\'s responsibility.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['deploy_checks', 'health_checks', 'fips_validator', 'audit_logging', 'hmac_chain'],
        ));

        // --- Article 17: Mutual Recognition ---

        $catalog->register(new Control(
            id: 'eIDAS-Art17',
            framework: 'eidas',
            title: 'Electronic Identification Mutual Recognition',
            description: 'Member States shall recognise electronic identification means issued in '
                . 'other Member States. Framework supports pluggable authentication guards '
                . 'that can integrate with eID schemes from different Member States. The '
                . 'LevelOfAssurance enum provides the common vocabulary for cross-border '
                . 'assurance level mapping.',
            status: ControlStatus::Partial,
            frameworkFeatures: ['level_of_assurance', 'authentication', 'pluggable_guards'],
        ));
    }
}
