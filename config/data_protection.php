<?php

declare(strict_types=1);

/**
 * Data Protection Configuration
 *
 * Retention policies, consent tracking, and data subject rights settings.
 * Each retention policy defines how long a category of data is kept before
 * becoming eligible for automated purging.
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Data Retention Policies
    |--------------------------------------------------------------------------
    |
    | Each entry defines a data category, its retention period in days, and
    | the legal or regulatory basis for that period. A retention_days value
    | of 0 means indefinite retention (no automatic expiry).
    |
    */
    'retention' => [
        [
            'category' => 'audit_logs',
            'retention_days' => 2555, // ~7 years (SOX, PCI DSS)
            'legal_basis' => 'SOX Section 802 / PCI DSS Requirement 10.7',
        ],
        [
            'category' => 'user_sessions',
            'retention_days' => 90,
            'legal_basis' => 'Operational necessity',
        ],
        [
            'category' => 'access_logs',
            'retention_days' => 365,
            'legal_basis' => 'NIS2 Article 23 / ISO 27001 A.12.4',
        ],
        [
            'category' => 'payment_records',
            'retention_days' => 2555, // ~7 years
            'legal_basis' => 'PSD2 Article 45 / PCI DSS Requirement 3.1',
        ],
        [
            'category' => 'consent_records',
            'retention_days' => 1825, // ~5 years
            'legal_basis' => 'GDPR Article 7(1) — proof of consent',
        ],
        [
            'category' => 'health_records',
            'retention_days' => 3650, // ~10 years
            'legal_basis' => 'MDR Article 10(8) / HL7 FHIR retention guidance',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Purge Settings
    |--------------------------------------------------------------------------
    |
    | Controls how automated purging behaves.
    |
    */
    'purge' => [
        // Maximum number of records to purge per batch run
        'batch_size' => 1000,

        // Whether to log each purge operation to the audit trail
        'audit_purge_operations' => true,

        // Dry-run mode: count but do not delete expired records
        'dry_run' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Consent Tracking
    |--------------------------------------------------------------------------
    |
    | Default purposes and settings for the consent management subsystem.
    |
    */
    'consent' => [
        // Require explicit consent before processing (GDPR-style opt-in)
        'require_explicit' => true,

        // Default consent purposes recognized by the application
        'purposes' => [
            'essential_processing',
            'analytics',
            'marketing_communications',
            'data_sharing_third_party',
        ],
    ],
];
