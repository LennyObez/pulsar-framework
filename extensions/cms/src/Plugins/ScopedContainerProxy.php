<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Content\RedirectRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Navigation\MenuRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Taxonomy\TaxonomyRepositoryInterface;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function in_array;

/**
 * Policy layer that limits which services a plugin can resolve from the container.
 *
 * Every plugin gets access to the base allowlist. Plugins that declare capabilities
 * in their manifest (e.g., "content", "media", "commerce") receive additional
 * services mapped to those capabilities.
 *
 * Denies access to sensitive services such as MasterKey, AuditLoggerInterface,
 * RoleRegistryInterface, and all Internal\ namespaced classes.
 * @api
 */
#[Api(since: '1.0.0')]
/**
 * @psalm-api Public proxy constructed by CmsPluginManager and exposed to plugin
 *            register() / boot() so they can resolve a curated subset of services.
 */
final readonly class ScopedContainerProxy
{
    /** @var list<class-string> Base services available to all plugins. */
    private const array BASE_SERVICES = [
        LoggerInterface::class,
        TaggedCacheInterface::class,
        EventDispatcherInterface::class,
    ];

    /**
     * Maps manifest capability strings to the additional services they unlock.
     *
     * @var array<string, list<class-string>>
     */
    private const array CAPABILITY_SERVICES = [
        'content' => [
            ContentRepositoryInterface::class,
            RedirectRepositoryInterface::class,
        ],
        'media' => [
            MediaRepositoryInterface::class,
        ],
        'settings' => [
            SettingsServiceInterface::class,
        ],
        'taxonomy' => [
            TaxonomyRepositoryInterface::class,
        ],
        'comments' => [
            CommentRepositoryInterface::class,
        ],
        'navigation' => [
            MenuRepositoryInterface::class,
        ],
    ];

    /** @var list<class-string> Effective allowlist for this plugin instance. */
    private array $allowedServices;

    /**
     * @param list<string> $capabilities Capability strings declared in the plugin manifest
     */
    public function __construct(
        private ContainerInterface $container,
        private string $pluginSlug,
        array $capabilities = [],
    ) {
        $this->allowedServices = self::resolveAllowedServices($capabilities);
    }

    /**
     * Resolve a service from the container if it is in the allowed list.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     *
     * @throws RuntimeException If the service is not allowed or not available
     */
    public function get(string $id): object
    {
        if (!in_array($id, $this->allowedServices, true)) {
            throw new RuntimeException(
                "Plugin '$this->pluginSlug' is not allowed to access service '$id'",
            );
        }

        if (!$this->container->has($id)) {
            throw new RuntimeException(
                "Service '$id' is not available in the container",
            );
        }

        /** @var T */
        return $this->container->get($id);
    }

    /**
     * Check if a service is available and allowed.
     */
    public function has(string $id): bool
    {
        return in_array($id, $this->allowedServices, true) && $this->container->has($id);
    }

    /**
     * Build the effective allowlist from base services + capability-granted services.
     *
     * @param list<string> $capabilities
     * @return list<class-string>
     */
    private static function resolveAllowedServices(array $capabilities): array
    {
        $seen = [];
        foreach (self::BASE_SERVICES as $service) {
            $seen[$service] = true;
        }

        foreach ($capabilities as $capability) {
            if (array_key_exists($capability, self::CAPABILITY_SERVICES)) {
                foreach (self::CAPABILITY_SERVICES[$capability] as $service) {
                    $seen[$service] = true;
                }
            }
        }

        // When no capabilities are declared, grant all capability services for backwards compatibility
        if ($capabilities === []) {
            foreach (self::CAPABILITY_SERVICES as $capabilityServices) {
                foreach ($capabilityServices as $service) {
                    $seen[$service] = true;
                }
            }
        }

        return array_keys($seen);
    }
}
