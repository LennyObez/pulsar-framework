<?php

declare(strict_types=1);

namespace Pulsar\Core;

/**
 * Pulsar Kernel
 *
 * The kernel is responsible for bootstrapping the application,
 * managing the lifecycle, and orchestrating the request/response cycle.
 */
final class Kernel
{
    private bool $booted = false;

    /**
     * Boot the kernel.
     *
     * This method initializes all core services and prepares
     * the application for handling requests.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
    }

    /**
     * Check if the kernel has been booted.
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Shutdown the kernel.
     *
     * Performs cleanup and releases resources.
     */
    public function shutdown(): void
    {
        $this->booted = false;
    }
}
