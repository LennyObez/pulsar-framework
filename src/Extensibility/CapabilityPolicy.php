<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use NoDiscard;
use Pulsar\Api\Api;

use function in_array;

/**
 * Maps trust tiers to their granted capabilities.
 *
 * Use the defaults() factory for the standard Pulsar policy,
 * or construct with custom grants for testing or specialized deployments.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CapabilityPolicy
{
    /**
     * @param array<string, list<ExtensionCapability>> $grants Tier value => granted capabilities
     */
    public function __construct(
        private array $grants,
    ) {}

    /**
     * Create the default Pulsar capability policy.
     */
    #[NoDiscard]
    public static function defaults(): self
    {
        return new self([
            // CORE — first-party framework infrastructure (auth, orm, security).
            // Full trust: every capability, including the crown jewels
            // (ContainerWrite override, CryptoKeyAccess, ProcessExec).
            TrustTier::Core->value => ExtensionCapability::cases(),

            // VERIFIED — audited first- or third-party, incl. bundled products.
            // Everything needed to ship a full-featured extension EXCEPT the
            // three crown jewels: it cannot read the master key
            // (CryptoKeyAccess), spawn processes (ProcessExec), or OVERRIDE an
            // existing binding (ContainerWrite) — so it can never hijack a core
            // security service. It registers its OWN services via ServiceRegister
            // (kept, being neither excluded below nor a crown jewel).
            TrustTier::Verified->value => array_values(array_filter(
                ExtensionCapability::cases(),
                static fn(ExtensionCapability $c): bool => !in_array($c, [
                    ExtensionCapability::CryptoKeyAccess,
                    ExtensionCapability::ProcessExec,
                    ExtensionCapability::ContainerWrite,
                ], true),
            )),

            // COMMUNITY — unaudited third-party. A minimal but genuinely
            // functional set: resolve services, register its OWN services and
            // own-prefix routes and commands, do crypto operations, and write
            // audit entries. ServiceRegister (added here) is what lets a
            // community extension register its services safely — it cannot
            // override a core service, so the rc.12 hole (ContainerWrite
            // hijacking Session/Auth/CsrfGuard) stays closed while the
            // legitimate need to register services is met first-class rather
            // than through RouteRegister/CommandRegister workarounds.
            TrustTier::Community->value => [
                ExtensionCapability::ContainerRead,
                ExtensionCapability::ServiceRegister,
                ExtensionCapability::RouteRegister,
                ExtensionCapability::CryptoOperations,
                ExtensionCapability::CommandRegister,
                ExtensionCapability::AuditWrite,
            ],

            // UNTRUSTED — experimental/sandboxed. Read-only container access.
            TrustTier::Untrusted->value => [
                ExtensionCapability::ContainerRead,
            ],
        ]);
    }

    /**
     * Check if a tier has a specific capability.
     */
    public function allows(TrustTier $tier, ExtensionCapability $capability): bool
    {
        $granted = $this->grants[$tier->value] ?? [];

        return in_array($capability, $granted, true);
    }

    /**
     * Get all capabilities granted to a tier.
     *
     * @return list<ExtensionCapability>
     */
    #[NoDiscard]
    public function grantedCapabilities(TrustTier $tier): array
    {
        return $this->grants[$tier->value] ?? [];
    }
}
