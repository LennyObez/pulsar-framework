<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\Exception\ExtensionException;
use Pulsar\Routing\Router;
use Throwable;

/**
 * Bootstraps extensions into the kernel lifecycle.
 *
 * Manages the two-phase extension lifecycle:
 * 1. Register phase: All extensions register their services
 * 2. Boot phase: All extensions boot (in dependency order)
 */
final class ExtensionBootstrap
{
    public private(set) bool $registered = false;
    public private(set) bool $booted = false;

    public function __construct(
        public readonly ExtensionRegistry $registry,
        public readonly ExtensionLoader $loader,
    ) {}

    /**
     * Create a bootstrap instance with default loader.
     */
    public static function create(): self
    {
        return new self(new ExtensionRegistry(), new ExtensionLoader());
    }

    /**
     * Discover and load extensions from paths.
     *
     * @param list<string> $paths Directories to scan for extensions
     * @throws ExtensionException If loading fails
     */
    public function loadFromPaths(array $paths): void
    {
        $manifests = $this->loader->discover($paths);

        // Validate and sort by dependencies
        foreach ($manifests as $manifest) {
            $this->loader->validateCompatibility($manifest);
            $this->loader->validateExtensionClass($manifest);
        }

        $sorted = $this->loader->resolveDependencies($manifests);

        // Instantiate and register extensions
        foreach ($sorted as $manifest) {
            $extension = $this->loader->instantiate($manifest);
            $this->registry->add($extension, $manifest, ExtensionLifecycle::Validated);
        }
    }

    /**
     * Add an extension manually (for testing or programmatic registration).
     */
    public function addExtension(ExtensionInterface $extension, ExtensionManifest $manifest): void
    {
        $this->registry->add($extension, $manifest, ExtensionLifecycle::Validated);
    }

    /**
     * Register phase: Call register() on all extensions.
     *
     * @throws ExtensionException If registration fails
     */
    public function register(ContainerInterface $container): void
    {
        if ($this->registered) {
            return;
        }

        foreach ($this->registry->all() as $name => $extension) {
            $state = $this->registry->getState($name);

            if (!$state->canRegister()) {
                continue;
            }

            try {
                // Register service providers first
                foreach ($extension->providers() as $providerClass) {
                    /** @var ServiceProviderInterface $provider */
                    $provider = new $providerClass();
                    $provider->register($container);
                }

                // Then call extension's own register method
                $extension->register($container);

                $this->registry->setState($name, ExtensionLifecycle::Registered);
            } catch (Throwable $e) {
                $this->registry->setState($name, ExtensionLifecycle::Failed);
                throw ExtensionException::registrationFailed($name, $e->getMessage());
            }
        }

        $this->registered = true;
    }

    /**
     * Boot phase: Call boot() on all extensions.
     *
     * @throws ExtensionException If booting fails
     */
    public function boot(ContainerInterface $container, Router $router): void
    {
        if ($this->booted) {
            return;
        }

        if (!$this->registered) {
            throw new ExtensionException('Extensions must be registered before booting');
        }

        foreach ($this->registry->all() as $name => $extension) {
            $state = $this->registry->getState($name);

            if (!$state->canBoot()) {
                continue;
            }

            try {
                $extension->boot($container, $router);
                $this->registry->setState($name, ExtensionLifecycle::Booted);
            } catch (Throwable $e) {
                $this->registry->setState($name, ExtensionLifecycle::Failed);
                throw ExtensionException::bootFailed($name, $e->getMessage());
            }
        }

        $this->booted = true;
    }

    /**
     * Get all commands provided by extensions.
     *
     * @return list<string> Command class names
     */
    public function getCommands(): array
    {
        $commands = [];

        foreach ($this->registry->allManifests() as $manifest) {
            if ($manifest->provides->hasCommands()) {
                $commands = [...$commands, ...$manifest->provides->commands];
            }
        }

        return $commands;
    }
}
