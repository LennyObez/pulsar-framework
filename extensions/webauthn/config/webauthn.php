<?php

declare(strict_types=1);

/**
 * WebAuthn/FIDO2 authentication configuration.
 *
 * @see \Pulsar\Extension\WebAuthn\Config\WebAuthnConfig
 */
return [
    // Relying Party name (displayed to the user by the authenticator)
    'rp_name' => getenv('WEBAUTHN_RP_NAME') ?: 'Pulsar Application',

    // Relying Party ID (the effective domain for credential scoping)
    'rp_id' => getenv('WEBAUTHN_RP_ID') ?: 'localhost',

    // Origin for challenge verification (must match the browser's origin)
    'origin' => getenv('WEBAUTHN_ORIGIN') ?: 'https://localhost',

    // User verification requirement: 'required', 'preferred', 'discouraged'
    'user_verification' => 'preferred',

    // Attestation conveyance: 'none', 'direct', 'indirect'
    'attestation' => 'none',

    // Allowed attestation formats
    'allowed_formats' => ['none', 'packed'],

    // Challenge time-to-live in seconds
    'challenge_ttl_seconds' => 300,

    // Ceremony timeout in milliseconds (sent to the browser)
    'timeout' => 60000,
];
