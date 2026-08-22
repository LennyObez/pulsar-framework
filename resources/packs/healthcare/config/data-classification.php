<?php

declare(strict_types=1);

/**
 * Data classification configuration for healthcare applications.
 *
 * Supports the HIPAA minimum necessary standard by classifying data
 * fields by sensitivity level and controlling access accordingly.
 *
 * WARNING: This is a scaffold configuration. Review and adapt to your
 * specific data handling requirements.
 */
return [
    'classification' => [
        'levels' => [
            'public' => [
                'label' => 'Public',
                'requires_encryption' => false,
                'requires_audit' => false,
            ],
            'internal' => [
                'label' => 'Internal',
                'requires_encryption' => false,
                'requires_audit' => true,
            ],
            'confidential' => [
                'label' => 'Confidential',
                'requires_encryption' => true,
                'requires_audit' => true,
            ],
            'phi' => [
                'label' => 'Protected Health Information',
                'requires_encryption' => true,
                'requires_audit' => true,
            ],
            'restricted' => [
                'label' => 'Restricted (Psychotherapy Notes, HIV, Substance Abuse)',
                'requires_encryption' => true,
                'requires_audit' => true,
            ],
        ],

        'field_defaults' => [
            'patient_name' => 'phi',
            'date_of_birth' => 'phi',
            'social_security_number' => 'restricted',
            'medical_record_number' => 'phi',
            'diagnosis' => 'phi',
            'treatment_plan' => 'phi',
            'medication_list' => 'phi',
            'psychotherapy_notes' => 'restricted',
            'hiv_status' => 'restricted',
            'substance_abuse_records' => 'restricted',
            'appointment_date' => 'confidential',
            'provider_name' => 'internal',
            'facility_name' => 'public',
        ],
    ],
];
