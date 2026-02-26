<?php

declare(strict_types=1);

/**
 * Extension Trust Configuration
 *
 * Maps extension names to their allowed trust tiers and optional
 * additional capability grants. The effective trust tier for an
 * extension is min(requested_tier, allowed_tier).
 *
 * Trust tiers (highest to lowest):
 *   - core:       First-party framework extensions — full access
 *   - verified:   Audited third-party — all except CryptoKeyAccess, ProcessExec
 *   - community:  Unaudited third-party — limited capabilities (default for unknown)
 *   - untrusted:  Experimental/sandboxed — read-only container access
 *
 * To elevate a community extension to verified:
 *   'vendor/extension' => ['tier' => 'verified'],
 *
 * To grant a specific capability to a community extension:
 *   'vendor/extension' => [
 *       'tier' => 'community',
 *       'additional_capabilities' => ['DatabaseRaw', 'NetworkEgress'],
 *   ],
 *
 * @package Pulsar\Config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted Extensions
    |--------------------------------------------------------------------------
    |
    | First-party extensions are pre-configured as core tier.
    | Add third-party extensions here to elevate their trust tier
    | or grant specific capabilities.
    |
    */
    'trusted_extensions' => [
        'pulsar/example' => ['tier' => 'core'],
        'pulsar/observability-export' => ['tier' => 'core'],
        'pulsar/psr7-bridge' => ['tier' => 'core'],
        'pulsar/mcp-server' => ['tier' => 'core'],
        'pulsar/studio' => ['tier' => 'core'],
        'pulsar/admin' => ['tier' => 'core'],
        'pulsar/orm' => ['tier' => 'core'],
        'pulsar/payments' => ['tier' => 'core'],
        'pulsar/social-sso' => ['tier' => 'core'],
        'pulsar/opentelemetry' => ['tier' => 'core'],
    ],
];
