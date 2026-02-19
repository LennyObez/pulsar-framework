<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Extension\Cms\Media\MediaRepositoryInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use RuntimeException;

use function in_array;

/**
 * Policy layer that limits which services a plugin can resolve from the container.
 *
 * Allows access to a curated set of safe services. Denies access to sensitive
 * services such as MasterKey, AuditLoggerInterface, RoleRegistryInterface, and
 * all Internal\ namespaced classes.
 */
#[Api(since: '1.0.0')]
final readonly class ScopedContainerProxy
{
    /** @var list<class-string> */
    private const array ALLOWED_SERVICES = [
        LoggerInterface::class,
        TaggedCacheInterface::class,
        EventDispatcherInterface::class,
        ContentRepositoryInterface::class,
        MediaRepositoryInterface::class,
        SettingsServiceInterface::class,
    ];

    public function __construct(
        private ContainerInterface $container,
        private string $pluginSlug,
    ) {}

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
        if (!in_array($id, self::ALLOWED_SERVICES, true)) {
            throw new RuntimeException(
                "Plugin '{$this->pluginSlug}' is not allowed to access service '{$id}'",
            );
        }

        if (!$this->container->has($id)) {
            throw new RuntimeException(
                "Service '{$id}' is not available in the container",
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
        return in_array($id, self::ALLOWED_SERVICES, true) && $this->container->has($id);
    }
}
