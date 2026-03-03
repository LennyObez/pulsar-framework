<?php

declare(strict_types=1);

namespace Pulsar\Container\Resolution;

use InvalidArgumentException;
use Pulsar\Api\Internal;
use Pulsar\Container\ContainerInterface;

use function class_exists;
use function interface_exists;
use function is_subclass_of;
use function sprintf;

/**
 * Resolves a container service from a configured FQCN string while refusing
 * arbitrary class instantiation.
 *
 * Pulsar service providers commonly let an operator pick an alternate
 * implementation by writing a class string into config:
 *
 *     // config/payments.php
 *     'idempotency' => ['store' => '\\App\\My\\Custom\\Store'],
 *
 * The naive realisation is `$container->get($config->idempotency->store)`,
 * which behaves as a generic class instantiator: any class registered in
 * the container can be reached by name. If the config value reaches the
 * code path through an env var, an attacker who controls that env var
 * controls which service is constructed — F3.1 / F3.2 / F17.1 / F21.2 /
 * F22.3 / F26.5 / F32.4 in the findings register, all the same shape.
 *
 * This resolver narrows the call-site contract to:
 *   1. The configured value must name a class that *currently exists*
 *      in the autoloader.
 *   2. The class must be a subtype of the expected interface for this
 *      slot (`IdempotencyStoreInterface`, `PaymentProviderInterface`,
 *      …). Anything else — even other services registered in the
 *      container — is refused with a precise diagnostic.
 *   3. Only after both checks does the container resolve the FQCN.
 *
 * Service providers should call this helper from the `default =>` arm of
 * any `match` over a config-string-driven dispatch.
 */
#[Internal]
final class TypedServiceResolver
{
    /**
     * @template T of object
     *
     * @param class-string<T> $expectedInterface
     *
     * @return T
     *
     * @throws InvalidArgumentException When the configured FQCN does not
     *                                  satisfy the type contract.
     */
    public static function resolve(
        ContainerInterface $container,
        string $configuredFqcn,
        string $expectedInterface,
        string $configKey,
    ): object {
        if ($configuredFqcn === '') {
            throw new InvalidArgumentException(sprintf(
                'Empty service identifier configured for "%s". Refusing to resolve to prevent arbitrary class instantiation.',
                $configKey,
            ));
        }

        if (!class_exists($configuredFqcn) && !interface_exists($configuredFqcn)) {
            throw new InvalidArgumentException(sprintf(
                'Configured service "%s" for "%s" does not name a known class or interface. '
                . 'Refusing to resolve to prevent arbitrary class instantiation.',
                $configuredFqcn,
                $configKey,
            ));
        }

        if ($configuredFqcn !== $expectedInterface
            && !is_subclass_of($configuredFqcn, $expectedInterface, true)
        ) {
            throw new InvalidArgumentException(sprintf(
                'Configured service "%s" for "%s" must be a subtype of %s. '
                . 'Refusing to resolve to prevent arbitrary class instantiation.',
                $configuredFqcn,
                $configKey,
                $expectedInterface,
            ));
        }

        /** @var T */
        return $container->get($configuredFqcn);
    }
}
