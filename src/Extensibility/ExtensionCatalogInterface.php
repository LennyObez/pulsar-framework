<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\Exception\ExtensionException;

/**
 * Everything you may ask the extension registry, and nothing you may tell it.
 *
 * The registry itself is internal because two of its fourteen methods write:
 * `add()` puts an extension into the graph and `setState()` moves it through its
 * lifecycle. Both belong to the boot sequence alone — an extension that changed
 * its own state, or added a sibling, would break the ordering the kernel depends
 * on. The other twelve only answer questions, and answering them is a published
 * capability: a console command lists what is installed, a migration resolver
 * needs to know which extensions booted before it reads their migration paths.
 *
 * Splitting the two lets those callers depend on the readable half without the
 * writable one being reachable from outside boot. Every type named here is
 * already `#[Api]`, so nothing internal leaks through the signatures.
 */
#[Api(since: '1.0.0')]
interface ExtensionCatalogInterface
{
    /**
     * Check if an extension is registered.
     */
    public function has(string $name): bool;

    /**
     * Get an extension by name.
     *
     * @throws ExtensionException If not found
     */
    #[NoDiscard]
    public function get(string $name): ExtensionInterface;

    /**
     * Get a manifest by extension name.
     *
     * @throws ExtensionException If not found
     */
    public function getManifest(string $name): ExtensionManifest;

    /**
     * Get the lifecycle state of an extension.
     *
     * @throws ExtensionException If not found
     */
    public function getState(string $name): ExtensionLifecycle;

    /**
     * Get all registered extensions.
     *
     * @return array<string, ExtensionInterface>
     */
    public function all(): array;

    /**
     * Get all registered manifests.
     *
     * @return array<string, ExtensionManifest>
     */
    public function allManifests(): array;

    /**
     * Get extensions in a specific state.
     *
     * @return array<string, ExtensionInterface>
     */
    public function inState(ExtensionLifecycle $state): array;

    /**
     * Get the count of registered extensions.
     */
    public function count(): int;

    /**
     * Get extension names.
     *
     * @return list<string>
     */
    public function names(): array;

    /**
     * Check if all extensions are in the booted state.
     */
    public function allBooted(): bool;

    /**
     * Get extensions that have failed.
     *
     * @return array<string, ExtensionInterface>
     */
    public function failed(): array;

    /**
     * Check if any extensions have failed.
     */
    public function hasFailed(): bool;
}
