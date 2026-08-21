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
        // Off by default and least-privilege at verified, like every other
        // bundled product. What it enforces is the EU AI Act — an Article 5
        // prohibited practice and the pre-market obligations of a high-risk
        // system, refused at deploy() by gates that sit behind no configuration
        // key — and it enforces all of it inside the sandbox. It is kind=product
        // because an application with no AI system should not carry an AI
        // management system it never asked for; an operator opts in via
        // extensions.enabled_products.
        //
        // This entry read 'core' until rc.12, on the recorded ground that the
        // sandbox's deny-by-default rule "refuses any service id the framework's
        // restriction map does not classify — which is every id an extension
        // registers for itself". That was not true of this tree. An id the
        // extension bound through its own scope is rescued by
        // Internal\ScopeRegistrations, and a type it ships is rescued by
        // ScopedContainerProxy::isOwnCode(); both are consulted in
        // assertCanResolve() after the restriction map, so the extension's own
        // contracts resolved at verified all along.
        //
        // Booting it at verified named the real defect on the first try, and it
        // was the HOST's service, not the extension's: the
        // Pulsar\Extensibility\ExtensionConfigRegistry every provider reads its own
        // config out of was in no category of the restriction map, so it fell
        // to deny-by-default. pulsar/booking and pulsar/payments failed the same
        // way, inside boot() rather than on first resolution. The registry is now
        // classified, and the proxy hands back a view narrowed to the sections
        // the receiving extension itself ships, so no tier reads another
        // extension's credentials out of it. See ServiceRestrictionMap and
        // ExtensionConfigRegistry::restrictedTo().
        //
        // ShippedDeploymentGateTest boots this extension at whatever tier
        // THIS FILE grants it and executes the gates through the resulting scope,
        // so the next tier change is measured rather than argued.
        'pulsar/ai-governance' => ['tier' => 'verified'],
        // Analytics pseudonymises the visitor. Its AnalyticsKeyManager derives
        // the rotating salt that hashes an IP and user agent into a visitor id,
        // and derives it from the master key — which is what makes the id
        // unlinkable across sites and irreversible after rotation, and is the
        // whole of the extension's GDPR/ePrivacy claim.
        //
        // Without CryptoKeyAccess the provider's `has(MasterKey)` guard is false
        // and it registers no key manager, so TrackingServiceInterface,
        // StatsServiceInterface and GoalServiceInterface never bind and the
        // extension ships inert — a silence BundledExtensionContractTest now
        // measures against the same boot at core. Granting the key is the only
        // answer that leaves the pseudonymisation in place: the alternative is
        // an analytics extension that either does nothing or stores raw
        // identifiers, and the second is not on offer here.
        'pulsar/analytics' => [
            'tier' => 'verified',
            'additional_capabilities' => ['CryptoKeyAccess'],
        ],
        'pulsar/auth' => ['tier' => 'core'],
        'pulsar/booking' => ['tier' => 'verified'],
        // The CMS derives its own keys and cannot run without one. Its
        // CmsKeyManager takes the master key and derives subkeys from it — the
        // pepper that hashes stored API keys, the HMAC key behind client
        // fingerprints, the signing key for preview links — and
        // CmsCoreServiceProvider refuses to finish registering when the manager
        // is absent, because a CMS that silently stops signing preview links is
        // worse than one that will not boot.
        //
        // CryptoKeyAccess is therefore the capability it actually needs, and
        // this grant is the least-privilege way to give it: cms keeps every
        // other Verified restriction, including the one that matters most —
        // ContainerWrite, so it still cannot override a core security binding.
        // Elevating it to core would have bought the same key along with
        // ProcessExec and the override power, to fix one dependency.
        //
        // It is written here, in the host's file, rather than assumed by the
        // sandbox, because granting an extension the master key is the
        // operator's decision and this file is the record of it.
        'pulsar/cms' => [
            'tier' => 'verified',
            'additional_capabilities' => ['CryptoKeyAccess'],
        ],
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
        // Payments signs its idempotency records with a master-key-derived
        // HMAC, so a tampered cache row cannot replay as a forged gateway
        // response. SignedIdempotencyEnvelope resolves KeyProviderInterface,
        // which costs CryptoKeyAccess.
        //
        // The grant is here rather than in the sandbox for the same reason as
        // the CMS above, and it is more urgent than a boot failure would have
        // been: the binding is a lazy closure, so the denial did not surface at
        // boot at all. It waited for the first idempotent payment.
        'pulsar/payments' => [
            'tier' => 'verified',
            'additional_capabilities' => ['CryptoKeyAccess'],
        ],
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
