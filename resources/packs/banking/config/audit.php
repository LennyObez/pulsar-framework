<?php

declare(strict_types=1);

/**
 * Audit trail configuration for financial operations.
 *
 * Tracks all data access and modifications for regulatory compliance.
 */
return [
    'enabled' => true,

    'storage' => [
        'driver' => 'database',
        'table' => 'audit_log',
        'connection' => null,
    ],

    'events' => [
        'transaction.created' => ['severity' => 'info', 'retain_days' => 2555],
        'transaction.modified' => ['severity' => 'warning', 'retain_days' => 2555],
        'transaction.deleted' => ['severity' => 'critical', 'retain_days' => 2555],
        'account.accessed' => ['severity' => 'info', 'retain_days' => 365],
        'account.modified' => ['severity' => 'warning', 'retain_days' => 2555],
        'payment.initiated' => ['severity' => 'info', 'retain_days' => 2555],
        'payment.completed' => ['severity' => 'info', 'retain_days' => 2555],
        'payment.failed' => ['severity' => 'warning', 'retain_days' => 2555],
        'kyc.verification_started' => ['severity' => 'info', 'retain_days' => 2555],
        'kyc.verification_completed' => ['severity' => 'info', 'retain_days' => 2555],
    ],

    'capture' => [
        'user_id' => true,
        'ip_address' => true,
        'user_agent' => true,
        'request_id' => true,
        'before_state' => true,
        'after_state' => true,
    ],
];
