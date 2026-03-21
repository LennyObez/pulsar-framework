<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Exception;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\ExtensionCapability;
use Pulsar\Extensibility\TrustTier;

use function sprintf;
use function ucfirst;

/**
 * Thrown when an extension attempts an operation denied by its trust tier.
 *
 * Error messages include the denied capability, the extension's effective tier,
 * and remediation guidance for the host application.
 */
#[Api(since: '1.0.0')]
final class CapabilityDeniedException extends ExtensionException
{
    /**
     * Denied access to a specific container service.
     */
    #[NoDiscard]
    public static function forService(
        string $serviceId,
        TrustTier $tier,
        ExtensionCapability $required,
    ): self {
        return new self(sprintf(
            'Cannot resolve service "%s": %s tier does not have %s capability. '
            . 'Grant this capability in config/extensions.php by adding '
            . "'additional_capabilities' => ['%s'].",
            $serviceId,
            ucfirst($tier->value),
            $required->name,
            $required->name,
        ));
    }

    /**
     * Denied a container operation (bind/instance).
     */
    #[NoDiscard]
    public static function forCapability(
        TrustTier $tier,
        ExtensionCapability $denied,
    ): self {
        return new self(sprintf(
            '%s tier does not have %s capability. '
            . 'Elevate the extension tier or grant the capability in config/extensions.php.',
            ucfirst($tier->value),
            $denied->name,
        ));
    }

    /**
     * Denied access to an unknown (unclassified) service.
     */
    #[NoDiscard]
    public static function forUnknownService(
        string $serviceId,
        TrustTier $tier,
    ): self {
        return new self(sprintf(
            'Service "%s" is not classified in the service restriction map: '
            . 'denied by default for %s tier. '
            . 'Add the service to the safe allowlist or restriction map in ServiceRestrictionMap.',
            $serviceId,
            ucfirst($tier->value),
        ));
    }
}
