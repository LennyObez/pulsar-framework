<?php

declare(strict_types=1);

use Pulsar\Compliance\ComplianceFramework;

/**
 * Compliance profile configuration.
 *
 * Enable the regulatory frameworks your application must comply with.
 * The framework automatically resolves the most restrictive settings
 * across all enabled frameworks and applies them to session management,
 * password policies, audit retention, encryption, and breach notification.
 *
 * @see \Pulsar\Compliance\ComplianceProfileResolver
 * @see \Pulsar\Compliance\ComplianceProfile
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Compliance Frameworks
    |--------------------------------------------------------------------------
    |
    | List the ComplianceFramework enum cases your application must comply with.
    | The ComplianceProfileResolver will compute the strictest intersection of
    | all enabled frameworks and produce a ComplianceProfile that is injected
    | into security-sensitive components.
    |
    | Supported frameworks:
    |   ComplianceFramework::Soc2       — SOC 2 Trust Services Criteria
    |   ComplianceFramework::Hipaa      — HIPAA Security Rule (2026 NPRM)
    |   ComplianceFramework::Gdpr       — EU GDPR
    |   ComplianceFramework::PciDss     — PCI-DSS v4.0.1
    |   ComplianceFramework::Nis2       — NIS2 Directive (EU 2022/2555)
    |   ComplianceFramework::Iso27001   — ISO/IEC 27001:2022
    |   ComplianceFramework::Psd2       — PSD2 (Payment Services Directive 2)
    |   ComplianceFramework::Eidas      — eIDAS Regulation (EU No 910/2014)
    |   ComplianceFramework::Iso42001   — ISO/IEC 42001:2023 (AI Management)
    |   ComplianceFramework::Hl7Fhir    — HL7 FHIR (healthcare interop)
    |
    */
    'enabled_frameworks' => [
        ComplianceFramework::PciDss,
        ComplianceFramework::Gdpr,
        ComplianceFramework::Hipaa,
        ComplianceFramework::Soc2,
        ComplianceFramework::Nis2,
        ComplianceFramework::Iso27001,
        ComplianceFramework::Iso42001,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Engine
    |--------------------------------------------------------------------------
    |
    | The compliance verification engine continuously proves that security
    | controls are active and configured correctly. It detects configuration
    | regressions, cross-framework conflicts, and produces evidence for audits.
    |
    */
    'verification' => [
        'enabled' => true,
        'boot_check' => true,
        'evidence_interval' => 3600,
        'strict_mode' => false,
    ],
];
