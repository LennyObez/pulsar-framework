<?php

declare(strict_types=1);

/**
 * Audit trail configuration for legal practice management.
 *
 * Tracks document access, case modifications, and privilege assertions
 * for compliance and malpractice defense.
 */
return [
    'enabled' => true,

    'storage' => [
        'driver' => 'database',
        'table' => 'audit_log',
        'connection' => null,
    ],

    'events' => [
        'case.created' => ['severity' => 'info', 'retain_days' => 2555],
        'case.modified' => ['severity' => 'info', 'retain_days' => 2555],
        'case.closed' => ['severity' => 'info', 'retain_days' => 2555],
        'document.created' => ['severity' => 'info', 'retain_days' => 2555],
        'document.accessed' => ['severity' => 'info', 'retain_days' => 2555],
        'document.modified' => ['severity' => 'warning', 'retain_days' => 2555],
        'document.deleted' => ['severity' => 'critical', 'retain_days' => 2555],
        'document.privilege_asserted' => ['severity' => 'info', 'retain_days' => 2555],
        'client.created' => ['severity' => 'info', 'retain_days' => 2555],
        'deadline.created' => ['severity' => 'info', 'retain_days' => 2555],
        'deadline.modified' => ['severity' => 'warning', 'retain_days' => 2555],
    ],

    'capture' => [
        'user_id' => true,
        'ip_address' => true,
        'request_id' => true,
        'before_state' => true,
        'after_state' => true,
    ],
];
