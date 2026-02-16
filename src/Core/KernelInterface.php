<?php

declare(strict_types=1);

namespace Pulsar\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Config\ConfigManagerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionBootstrap;
use Pulsar\Routing\RouterInterface;

#[Api(since: '1.0.0')]
interface KernelInterface
{
    public function boot(): void;

    public function handle(ServerRequestInterface $request): ResponseInterface;

    public function run(): void;

    public function shutdown(): void;

    public function container(): ContainerInterface;

    public function router(): RouterInterface;

    /**
     * Whether the kernel has completed booting.
     */
    public bool $booted { get; }

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
