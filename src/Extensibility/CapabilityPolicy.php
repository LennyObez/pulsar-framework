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
            TrustTier::Core->value => ExtensionCapability::cases(),
            TrustTier::Verified->value => array_values(array_filter(
                ExtensionCapability::cases(),
                static fn(ExtensionCapability $c): bool => !in_array($c, [
                    ExtensionCapability::CryptoKeyAccess,
                    ExtensionCapability::ProcessExec,
                ], true),
            )),
            // Community extensions get read-only container access.
            // ContainerWrite was removed in 1.0.0-rc.12: it allowed arbitrary
            // service replacement, letting a community extension silently
            // override core security services (Session, Auth, CsrfGuard...).
            // Community extensions that need to register services must now
            // use RouteRegister or CommandRegister, which go through the
            // ScopedContainerProxy write-allowlist.
            TrustTier::Community->value => [
                ExtensionCapability::ContainerRead,
                ExtensionCapability::RouteRegister,
                ExtensionCapability::CryptoOperations,
                ExtensionCapability::CommandRegister,
                ExtensionCapability::AuditWrite,
            ],
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
