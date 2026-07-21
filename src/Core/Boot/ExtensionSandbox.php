<?php

declare(strict_types=1);

namespace Pulsar\Core\Boot;

use Pulsar\Api\Internal;
use Pulsar\Config\TrustedExtensionsConfig;
use Pulsar\Extensibility\CapabilityPolicy;
use Pulsar\Extensibility\ExtensionBootstrap;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Engages the extension capability sandbox at kernel boot.
 *
 * The scoping machinery in {@see ExtensionBootstrap} (ScopedContainerProxy /
 * ScopedRouterProxy) only activates once a {@see CapabilityPolicy} is attached.
 * Nothing in the production boot path did that, so the property stayed null and
 * every extension — first-party or not — received the raw container and router
 * (full host privileges). A malicious or compromised third-party extension
 * could then replace core security services or register arbitrary routes.
 *
 * This attaches the default capability policy plus the host trust allow-list
 * from `config/extensions.php`, so:
 *   - Extensions the host lists (the framework's own bundled extensions ship
 *     listed at Core) keep their declared tier; Core bypasses the proxy, so
 *     first-party extensions run exactly as before.
 *   - Every other extension is capped at Community
 *     ({@see TrustedExtensionsConfig::effectiveTier} takes min(requested,
 *     allowed) with Community the default) — deny-by-default. A manifest cannot
 *     self-elevate to Core; trust is granted by the host, never claimed by the
 *     extension.
 *
 * Boot-time only; extracted from {@see \Pulsar\Core\Kernel}.
 */
#[Internal]
final class ExtensionSandbox
{
    /**
     * Attach the capability policy and host trust config to the bootstrap.
     *
     * A no-op when a policy was already configured explicitly (tests and
     * advanced embedders that supply their own policy keep control).
     *
     * @param string|null $configPath The project's config directory (parent of
     *                                 extensions.php), typically from
     *                                 ConfigManager::configPath().
     */
    public static function harden(ExtensionBootstrap $bootstrap, ?string $configPath): void
    {
        if ($bootstrap->capabilityPolicy !== null) {
            return;
        }

        $bootstrap->capabilityPolicy = CapabilityPolicy::defaults();
        $bootstrap->trustedExtensionsConfig = TrustedExtensionsConfig::fromArray(
            self::trustedExtensions($configPath),
        );
    }

    /**
     * Read the `trusted_extensions` allow-list from `config/extensions.php`.
     *
     * Fail-closed: when the file is absent or malformed this returns an empty
     * list, so the sandbox still engages and unknown extensions are capped at
     * Community — the check never silently disables itself by leaving the
     * policy null.
     *
     * @return array<array-key, mixed>
     */
    private static function trustedExtensions(?string $configPath): array
    {
        if ($configPath === null) {
            return [];
        }

        $file = $configPath . DIRECTORY_SEPARATOR . 'extensions.php';

        if (!is_file($file)) {
            return [];
        }

        /** @var mixed $data */
        $data = require $file;

        if (
            !is_array($data)
            || !isset($data['trusted_extensions'])
            || !is_array($data['trusted_extensions'])
        ) {
            return [];
        }

        return $data['trusted_extensions'];
    }
}
