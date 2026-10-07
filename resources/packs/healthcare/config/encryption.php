<?php

declare(strict_types=1);

/**
 * Encryption configuration for healthcare data.
 *
 * HIPAA Security Rule (164.312(a)(2)(iv) and 164.312(e)(2)(ii))
 * requires encryption for PHI at rest and in transit.
 *
 * WARNING: These are scaffold defaults. You MUST configure proper
 * key management before handling any real PHI.
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
        'patient_name' => ['encrypt' => true, 'searchable' => false],
        'date_of_birth' => ['encrypt' => true, 'searchable' => false],
        'ssn' => ['encrypt' => true, 'searchable' => false],
        'medical_record_number' => ['encrypt' => true, 'searchable' => true],
        'diagnosis' => ['encrypt' => true, 'searchable' => false],
        'medication' => ['encrypt' => true, 'searchable' => false],
    ],
];
