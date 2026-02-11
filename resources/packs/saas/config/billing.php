<?php

declare(strict_types=1);

/**
 * Billing integration configuration.
 *
 * Stub for integrating with a payment provider such as Stripe or Paddle.
 *
 * WARNING: This is a scaffold configuration. You MUST integrate with a
 * certified payment provider before accepting payments.
 */
return [
    'billing' => [
        'provider' => 'stripe',

        'stripe' => [
            'secret_key_env' => 'STRIPE_SECRET_KEY',
            'publishable_key_env' => 'STRIPE_PUBLISHABLE_KEY',
            'webhook_secret_env' => 'STRIPE_WEBHOOK_SECRET',
        ],

        'trial' => [
            'enabled' => true,
            'days' => 14,
        ],

        'grace_period' => [
            'enabled' => true,
            'days' => 7,
        ],

        'invoicing' => [
            'auto_generate' => true,
            'send_email' => true,
        ],
    ],
];
