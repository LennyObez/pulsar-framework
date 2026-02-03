<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use function count;

use Pulsar\Extensibility\Exception\ExtensionException;

/**
 * Registry for managing loaded extensions and their lifecycle states.
 */
final class ExtensionRegistry
{
    /**
     * @var array<string, ExtensionInterface>
     */
    private array $extensions = [];

    /**
     * @var array<string, ExtensionManifest>
     */
    private array $manifests = [];

    /**
     * @var array<string, ExtensionLifecycle>
     */
    private array $states = [];

    /**
     * Register an extension with its manifest.
     *
     * @throws ExtensionException If extension is already registered
     */
    public function add(
        ExtensionInterface $extension,
        ExtensionManifest $manifest,
        ExtensionLifecycle $state = ExtensionLifecycle::Discovered,
    ): void {
        $name = $extension->name();

        if ($this->has($name)) {
            throw ExtensionException::alreadyRegistered($name);
        }

        $this->extensions[$name] = $extension;
        $this->manifests[$name] = $manifest;
        $this->states[$name] = $state;
    }

    /**
     * Check if an extension is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->extensions[$name]);
    }

    /**
     * Get an extension by name.
     *
     * @throws ExtensionException If not found
     */
    public function get(string $name): ExtensionInterface
    {
        if (!$this->has($name)) {
            throw ExtensionException::notFound($name);
        }

        return $this->extensions[$name];
    }

    /**
     * Get a manifest by extension name.
     *
     * @throws ExtensionException If not found
     */
    public function getManifest(string $name): ExtensionManifest
    {
        if (!isset($this->manifests[$name])) {
            throw ExtensionException::notFound($name);
        }

        return $this->manifests[$name];
    }

    /**
     * Get the lifecycle state of an extension.
     *
     * @throws ExtensionException If not found
     */
    public function getState(string $name): ExtensionLifecycle
    {
        if (!isset($this->states[$name])) {
            throw ExtensionException::notFound($name);
        }

        return $this->states[$name];
    }

    /**
     * Update the lifecycle state of an extension.
     *
     * @throws ExtensionException If not found
     */
    public function setState(string $name, ExtensionLifecycle $state): void
    {
        if (!$this->has($name)) {
            throw ExtensionException::notFound($name);
        }

        $this->states[$name] = $state;
    }

    /**
     * Get all registered extensions.
     *
     * @return array<string, ExtensionInterface>
     */
    public function all(): array
    {
        return $this->extensions;
    }

    /**
     * Get all registered manifests.
     *
     * @return array<string, ExtensionManifest>
     */
    public function allManifests(): array
    {
        return $this->manifests;
    }

    /**
     * Get extensions in a specific state.
     *
     * @return array<string, ExtensionInterface>
     */
    public function inState(ExtensionLifecycle $state): array
    {
        return array_filter(
            $this->extensions,
            fn(string $name): bool => $this->states[$name] === $state,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Get the count of registered extensions.
     */
    public function count(): int
    {
        return count($this->extensions);
    }

    /**
     * Get extension names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->extensions);
    }

    /**
     * Check if all extensions are in the booted state.
     */
    public function allBooted(): bool
    {
        return $this->count() > 0
            && !array_any($this->states, static fn(ExtensionLifecycle $state): bool => $state !== ExtensionLifecycle::Booted);
    }

    /**
     * Get extensions that have failed.
     *
     * @return array<string, ExtensionInterface>
     */
    public function failed(): array
    {
        return $this->inState(ExtensionLifecycle::Failed);
    }

    /**
     * Check if any extensions have failed.
     */
    public function hasFailed(): bool
    {
        return $this->failed() !== [];
    }
}
