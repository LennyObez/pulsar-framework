<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Api;
use Pulsar\Core\KernelInterface;
use Throwable;

/**
 * Default FPM runtime: zero behavioral change from Kernel::run().
 *
 * Wraps the standard request lifecycle for PHP-FPM and CLI server.
 * beforeRequest/afterRequest are no-ops since FPM isolates requests
 * at the process level.
 * @api
 */
#[Api(since: '1.0.0')]
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
    public function beforeRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        // FPM provides process-level isolation: no sandbox needed
        return $request;
    }

    #[Override]
    public function afterRequest(ServerRequestInterface $request, ResponseInterface $response): void
    {
        // FPM provides process-level isolation: no cleanup needed
    }

    public function status(): RuntimeStatus
    {
        return $this->status;
    }
}
