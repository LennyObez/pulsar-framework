<?php

declare(strict_types=1);

return [
    'provider' => 'null',
    'default_currency' => 'USD',
    'webhook' => [
        'secret' => '',
        'path' => '/webhooks/payments',
        'tolerance_seconds' => 300,
        'signature_header' => 'X-Payments-Signature',
    ],
    'idempotency' => [
        'ttl_seconds' => 86400,
        'store' => 'memory',
        'max_key_length' => 256,
    ],
    'webhook_log' => [
        'ttl_seconds' => 259200,
        'store' => 'memory',
    ],
];
