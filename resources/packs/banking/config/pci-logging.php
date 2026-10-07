<?php

declare(strict_types=1);

/**
 * PCI-DSS-oriented logging configuration scaffold.
 *
 * Requirement 10: Log and monitor all access to network resources and cardholder data.
 *
 * WARNING: This is a scaffold configuration. Review and adapt to your specific
 * PCI-DSS scope before processing any cardholder data.
 */
return [
    'pci_logging' => [
        'enabled' => true,

        'mask_patterns' => [
            '/\b\d{13,19}\b/' => '****',
            '/\b\d{3,4}\b/' => '***',
        ],

        'never_log' => [
            'card_number',
            'cvv',
            'pin',
            'password',
            'secret',
            'token',
        ],

        'access_log' => [
            'enabled' => true,
            'include_user_id' => true,
            'include_source_ip' => true,
            'include_timestamp' => true,
            'include_resource' => true,
            'include_action' => true,
        ],

        'retention' => [
            'minimum_days' => 365,
            'recommended_days' => 2555,
        ],

        'integrity' => [
            'tamper_detection' => true,
            'hash_algorithm' => 'sha256',
        ],
    ],
];
