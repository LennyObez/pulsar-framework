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
    | Every first-party extension bundled with the framework is listed here at
    | core tier. The list is kept complete by a drift test
    | (tests/Unit/Core/Boot/ExtensionSandboxDriftTest) so a newly added bundled
    | extension cannot silently fall to the community cap and fail to boot — and,
    | more importantly, so the sandbox always engages: an extension absent from
    | this list runs at community tier regardless of the tier its own manifest
    | requests.
    |
    */
    'trusted_extensions' => [
        'pulsar/accessibility' => ['tier' => 'core'],
        'pulsar/admin' => ['tier' => 'core'],
        'pulsar/ai-governance' => ['tier' => 'core'],
        'pulsar/analytics' => ['tier' => 'core'],
        'pulsar/auth' => ['tier' => 'core'],
        'pulsar/booking' => ['tier' => 'core'],
        'pulsar/cms' => ['tier' => 'core'],
        'pulsar/devices' => ['tier' => 'core'],
        'pulsar/example' => ['tier' => 'core'],
        'pulsar/feedback' => ['tier' => 'core'],
        'pulsar/form' => ['tier' => 'core'],
        'pulsar/forum' => ['tier' => 'core'],
        'pulsar/graphql' => ['tier' => 'core'],
        'pulsar/grpc' => ['tier' => 'core'],
        'pulsar/health-status' => ['tier' => 'core'],
        'pulsar/mcp-server' => ['tier' => 'core'],
        'pulsar/messaging' => ['tier' => 'core'],
        'pulsar/observability' => ['tier' => 'core'],
        'pulsar/observability-export' => ['tier' => 'core'],
        'pulsar/opentelemetry' => ['tier' => 'core'],
        'pulsar/orm' => ['tier' => 'core'],
        'pulsar/payments' => ['tier' => 'core'],
        'pulsar/psr7-bridge' => ['tier' => 'core'],
        'pulsar/releases' => ['tier' => 'core'],
        'pulsar/social-sso' => ['tier' => 'core'],
        'pulsar/studio' => ['tier' => 'core'],
        'pulsar/subscriptions' => ['tier' => 'core'],
        'pulsar/tickets' => ['tier' => 'core'],
    ],
];
