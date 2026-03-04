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
        'check_revocation' => true,
        'trusted_issuers' => [],
        'validator' => 'default',
    ],
];
