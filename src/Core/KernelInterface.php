<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Pulsar\Api\Api;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Pulsar\Routing\RouterInterface;

#[Api]
interface KernelInterface
{
    public function boot(): void;

    public function handle(Request $request): Response;

    public function run(): void;

    public function shutdown(): void;

    public function container(): ContainerInterface;

    public function router(): RouterInterface;

    /**
     * Whether the kernel has completed booting.
     */
    public function isBooted(): bool;

    /**
     * Get the extension bootstrap instance.
     */
    public function extensionBootstrap(): ?ExtensionBootstrap;

    /**
     * Get the config manager instance.
     */
    public function configManager(): ?ConfigManagerInterface;

    /**
     * Get the boot profile (available after boot completes).
     */
    public function bootProfile(): ?BootProfile;
}
