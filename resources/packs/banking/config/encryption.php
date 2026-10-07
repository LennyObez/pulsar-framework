<?php

declare(strict_types=1);

/**
 * Encryption-at-rest configuration for financial data.
 *
 * WARNING: These are scaffold defaults. You MUST configure a proper
 * key management solution (HSM or certified KMS) before processing
 * any real financial data.
 */
return [
    'default' => 'aes-256-gcm',

    'drivers' => [
        'aes-256-gcm' => [
            'cipher' => 'aes-256-gcm',
            'key_provider' => 'env',
            'key_env_var' => 'ENCRYPTION_KEY',
        ],
    ],

    'fields' => [
        'account_number' => ['encrypt' => true, 'searchable' => false],
        'card_number' => ['encrypt' => true, 'searchable' => false],
        'routing_number' => ['encrypt' => true, 'searchable' => false],
        'tax_id' => ['encrypt' => true, 'searchable' => false],
    ],

    'key_rotation' => [
        'enabled' => false,
        'interval_days' => 90,
        'retain_previous_keys' => 3,
    ],
];
