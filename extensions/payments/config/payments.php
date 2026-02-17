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
    'payconiq' => [
        'merchant_id' => '',
        'api_key' => '',
        'webhook_secret' => '',
        'environment' => 'ext',
        'enabled' => false,
        'callback_url' => '',
        'payment_expiry_seconds' => 900,
    ],
    'bancontact' => [
        'enabled' => false,
        'preferred_language' => 'nl',
    ],
    'ideal' => [
        'enabled' => false,
        'provider' => 'stripe',
    ],
    'klarna' => [
        'enabled' => false,
        'region' => 'eu',
        'pay_later_enabled' => true,
        'pay_now_enabled' => true,
        'slice_it_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Country Payment Method Overrides
    |--------------------------------------------------------------------------
    |
    | Override the default payment methods available for specific countries.
    | Keys are ISO 3166-1 alpha-2 country codes (case-insensitive).
    | Values are lists of payment method identifiers.
    |
    | If a country is not listed here, the CurrencyResolver applies
    | automatic rules (Bancontact for BE, iDEAL for NL, SEPA for EU, etc.).
    |
    | Example:
    |   'BE' => ['card', 'paypal', 'bancontact', 'payconiq', 'sepa'],
    |   'NL' => ['card', 'paypal', 'ideal', 'sepa'],
    |
    */
    'country_payment_methods' => [],
];
