<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Override;
use Pulsar\Api\Api;
use Pulsar\Core\KernelInterface;
use Pulsar\Http\Request;
use Pulsar\Http\Response;
use Throwable;

/**
 * Default FPM runtime — zero behavioral change from Kernel::run().
 *
 * Wraps the standard request lifecycle for PHP-FPM and CLI server.
 * beforeRequest/afterRequest are no-ops since FPM isolates requests
 * at the process level.
 */
#[Api]
final class FpmRuntime implements RuntimeInterface
{
    private RuntimeStatus $status = RuntimeStatus::Stopped;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    /**
     * @throws Throwable If the kernel request lifecycle throws
     */
    #[Override]
    public function start(): void
    {
        $this->status = RuntimeStatus::Running;

        $this->kernel->run();

        $this->status = RuntimeStatus::Stopped;
    }

    #[Override]
    public function stop(): void
    {
        $this->status = RuntimeStatus::Stopping;
        $this->kernel->shutdown();
        $this->status = RuntimeStatus::Stopped;
    }

    #[Override]
    public function beforeRequest(Request $request): Request
    {
        // FPM provides process-level isolation — no sandbox needed
        return $request;
    }

    #[Override]
    public function afterRequest(Request $request, Response $response): void
    {
        // FPM provides process-level isolation — no cleanup needed
    }

    public function status(): RuntimeStatus
    {
        return $this->status;
    }
}
