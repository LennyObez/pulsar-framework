<?php

declare(strict_types=1);

return [
    'sca' => [
        'challenge_timeout_seconds' => 300,
        'challenge_store' => 'memory',
        'code_length' => 8,
    ],
    'risk' => [
        'low_threshold' => 0.3,
        'high_threshold' => 0.7,
        'velocity_window_seconds' => 3600,
        'velocity_max_count' => 10,
        'velocity_max_amount_minor_units' => 50000,
        'low_value_threshold_minor_units' => 3000,
        'velocity_tracker' => 'memory',
    ],
    'certificate' => [
        'require_qualified' => true,
        // Confirm certificates have not been revoked via OCSP (RFC 6960).
        'check_revocation' => true,
        // Policy for an INCONCLUSIVE revocation check (responder unreachable, no
        // OCSP pointer, unparseable answer). false = fail closed (reject unless
        // positively confirmed good); true = allow through with an audit log when
        // availability must be preferred. A confirmed revocation always rejects.
        'revocation_soft_fail' => false,
        'trusted_issuers' => [],
        // Path to a PEM bundle of trusted eIDAS QTSP CA certificates. Chain
        // verification anchors here; when null the validator FAILS CLOSED.
        'trusted_ca_bundle_path' => null,
        'validator' => 'default',
    ],
];
