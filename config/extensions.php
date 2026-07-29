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
        // Tier reflects trust, not enable-state. INFRASTRUCTURE and
        // SECURITY/COMPLIANCE extensions run at CORE (full trust). Bundled
        // APPLICATION/PRODUCT extensions run least-privilege at VERIFIED: they
        // register and decorate their own services but cannot override a core
        // binding (ContainerWrite), read the master key, or exec processes. The
        // split is drift-guarded by ExtensionSandboxDriftTest.
        'pulsar/accessibility' => ['tier' => 'core'],
        'pulsar/admin' => ['tier' => 'core'],
        'pulsar/ai-governance' => ['tier' => 'verified'],
        'pulsar/analytics' => ['tier' => 'verified'],
        'pulsar/auth' => ['tier' => 'core'],
        'pulsar/booking' => ['tier' => 'verified'],
        'pulsar/cms' => ['tier' => 'verified'],
        'pulsar/devices' => ['tier' => 'verified'],
        'pulsar/example' => ['tier' => 'core'],
        'pulsar/feedback' => ['tier' => 'verified'],
        'pulsar/form' => ['tier' => 'core'],
        'pulsar/forum' => ['tier' => 'verified'],
        'pulsar/graphql' => ['tier' => 'core'],
        'pulsar/grpc' => ['tier' => 'core'],
        'pulsar/health-status' => ['tier' => 'verified'],
        'pulsar/mcp-server' => ['tier' => 'core'],
        'pulsar/messaging' => ['tier' => 'verified'],
        'pulsar/observability' => ['tier' => 'core'],
        'pulsar/observability-export' => ['tier' => 'core'],
        'pulsar/opentelemetry' => ['tier' => 'core'],
        'pulsar/orm' => ['tier' => 'core'],
        'pulsar/payments' => ['tier' => 'verified'],
        'pulsar/psr7-bridge' => ['tier' => 'core'],
        'pulsar/releases' => ['tier' => 'verified'],
        'pulsar/social-sso' => ['tier' => 'core'],
        'pulsar/studio' => ['tier' => 'core'],
        'pulsar/subscriptions' => ['tier' => 'verified'],
        'pulsar/tickets' => ['tier' => 'verified'],
        // Compliance extensions live nested under extensions/compliance/*. They
        // are first-party and register services (ContainerWrite) via their
        // ServiceProviders, so they need core tier like every other bundled
        // extension. The drift guard globs both depths to keep this complete.
        'pulsar/data-act' => ['tier' => 'core'],
        'pulsar/dora' => ['tier' => 'core'],
        'pulsar/dsa' => ['tier' => 'core'],
        'pulsar/eidas' => ['tier' => 'core'],
        'pulsar/fhir' => ['tier' => 'core'],
        'pulsar/medical-devices' => ['tier' => 'core'],
        'pulsar/psd2' => ['tier' => 'core'],
    ],
];
