<?php

declare(strict_types=1);

/**
 * Protected Health Information (PHI) access logging configuration.
 *
 * HIPAA Security Rule (164.312(b)) requires audit controls to record
 * and examine activity in systems containing PHI.
 *
 * WARNING: This is a scaffold configuration. Review and adapt to your
 * specific HIPAA requirements before handling any real PHI.
 */
return [
    'phi_access_logging' => [
        'enabled' => true,

        'log_events' => [
            'phi.viewed' => true,
            'phi.created' => true,
            'phi.modified' => true,
            'phi.deleted' => true,
            'phi.exported' => true,
            'phi.printed' => true,
        ],

        'capture' => [
            'user_id' => true,
            'user_role' => true,
            'patient_id' => true,
            'data_category' => true,
            'access_reason' => true,
            'ip_address' => true,
            'timestamp' => true,
            'session_id' => true,
        ],

        'storage' => [
            'driver' => 'database',
            'table' => 'phi_access_log',
            'retention_days' => 2190,
        ],

        'alerts' => [
            'unusual_access_volume' => true,
            'after_hours_access' => true,
            'cross_department_access' => true,
        ],
    ],
];
