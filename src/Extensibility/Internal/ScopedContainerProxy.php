<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Internal;

use NoDiscard;
use Override;
use Pulsar\Container\BindingType;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\Exception\CapabilityDeniedException;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function in_array;

/**
 * Capability-gated proxy for ContainerInterface.
 *
 * Wraps a real container and enforces capability checks on service
 * resolution and binding operations based on the extension's trust tier.
 *
 * Design invariants:
 * - has() is always truthful (PSR-11 compliance)
 * - get() throws CapabilityDeniedException for denied services
 * - Deny-by-default for unknown services (non-Core tiers)
 *
 * @internal Not part of the public API
 */
final readonly class ScopedContainerProxy implements ContainerInterface
{
    /**
     * @param list<ExtensionCapability> $additionalCapabilities Per-extension extra grants
     */
    public function __construct(
        private ContainerInterface $inner,
        private TrustTier $tier,
        private CapabilityPolicy $policy,
        private ServiceRestrictionMap $restrictionMap,
        private array $additionalCapabilities = [],
    ) {}

    /**
     * Truthfully delegates to the real container: never lies about existence.
     */
    #[Override]
    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }

    /**
     * Resolve a service, enforcing capability checks.
     *
     * @throws CapabilityDeniedException If the extension's tier lacks the required capability
     */
    #[Override]
    #[NoDiscard]
    public function get(string $id): mixed
    {
        $this->assertCanResolve($id);

        return $this->inner->get($id);
    }

    #[Override]
    public function bind(string $id, callable|string $concrete, BindingType $type = BindingType::Singleton): void
    {
        $this->assertCanRegister($id);
        $this->inner->bind($id, $concrete, $type);
    }

    #[Override]
    public function singleton(string $id, callable|string $concrete): void
    {
        $this->assertCanRegister($id);
        $this->inner->singleton($id, $concrete);
    }

    #[Override]
    public function instance(string $id, object $instance): void
    {
        $this->assertCanRegister($id);
        $this->inner->instance($id, $instance);
    }

    #[Override]
    public function forgetInstance(string $id): void
    {
        $this->assertCanWrite();
        $this->inner->forgetInstance($id);
    }

    #[Override]
    public function setResolutionHints(?array $hints): void
    {
        $this->assertCanWrite();
        $this->inner->setResolutionHints($hints);
    }

    #[Override]
    #[NoDiscard]
    public function getBindings(): array
    {
        $this->assertCanRead();

        return $this->inner->getBindings();
    }

    #[Override]
    #[NoDiscard]
    public function getInstances(): array
    {
        $this->assertCanRead();

        return $this->inner->getInstances();
    }

    #[Override]
    public function call(callable $callable, array $params = []): mixed
    {
        return $this->inner->call($callable, $params);
    }

    private function assertCanResolve(string $serviceId): void
    {
        // Check restricted services first
        if ($this->restrictionMap->isRestricted($serviceId)) {
            $required = $this->restrictionMap->requiredCapability($serviceId);

            if ($required !== null && !$this->hasCapability($required)) {
                throw CapabilityDeniedException::forService($serviceId, $this->tier, $required);
            }

            return;
        }

        // Safe services are always allowed with ContainerRead
        if ($this->restrictionMap->isSafe($serviceId)) {
            return;
        }

        // Unknown service; deny by default for non-Core tiers
        if (!$this->tier->atLeast(TrustTier::Core)) {
            throw CapabilityDeniedException::forUnknownService($serviceId, $this->tier);
        }
    }

    private function assertCanRead(): void
    {
        if (!$this->hasCapability(ExtensionCapability::ContainerRead)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ContainerRead);
        }
    }

    private function assertCanWrite(): void
    {
        if (!$this->hasCapability(ExtensionCapability::ContainerWrite)) {
            throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ContainerWrite);
        }
    }

    /**
     * Registering a service the extension provides vs overriding an existing one.
     *
     * Rebinding an id that is ALREADY explicitly bound can hijack a core service
     * (the rc.12 Session/Auth/CsrfGuard override hole), so that override power is
     * ContainerWrite — Core only. Binding a NEW id — the extension's own service,
     * or filling an unbound extension point — is the lesser ServiceRegister power
     * available to Verified and Community. `has()` (PSR-11) is deliberately NOT
     * used here: it is true for any autowirable class, which would misclassify a
     * first-time registration as an override; only an EXPLICIT binding or cached
     * instance counts as "already registered".
     */
    private function assertCanRegister(string $id): void
    {
        $alreadyRegistered = in_array($id, $this->inner->getBindings(), true)
            || in_array($id, $this->inner->getInstances(), true);

        if ($alreadyRegistered) {
            $this->assertCanWrite();

            return;
        }

        if (
            $this->hasCapability(ExtensionCapability::ServiceRegister)
            || $this->hasCapability(ExtensionCapability::ContainerWrite)
        ) {
            return;
        }

        throw CapabilityDeniedException::forCapability($this->tier, ExtensionCapability::ServiceRegister);
    }

    private function hasCapability(ExtensionCapability $capability): bool
    {
        if ($this->policy->allows($this->tier, $capability)) {
            return true;
        }

        return in_array($capability, $this->additionalCapabilities, true);
    }
}
