<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;

/**
 * F3.14: optional lifecycle hook called when the kernel is asked
 * to release resources.
 *
 * Long-running SAPIs (RoadRunner, FrankenPHP, Swoole, queue
 * workers, supervised processes) recycle workers without
 * tearing the process down. Extensions that hold long-lived
 * resources — database / redis / grpc connections, log buffers,
 * in-flight worker pools — MUST close them in
 * `shutdown()` or the recycled worker leaks handles, fills the
 * file-descriptor table, and eventually fails to accept new
 * requests.
 *
 * The interface is opt-in (mirrors the existing
 * {@see PreBootExtensionInterface} / {@see PostBootExtensionInterface}
 * shape) so existing extensions that don't need cleanup keep
 * working unmodified. The kernel iterates loaded extensions
 * during shutdown and calls `shutdown()` on every one that
 * implements this contract; non-implementing extensions are
 * skipped.
 *
 * Phase ordering: register → preBoot → boot → postBoot →
 * (request lifecycle) → shutdown.
 */
#[Api(since: '1.0.0')]
interface ShutdownAwareExtensionInterface
{
    /**
     * Release resources held by this extension. Called once
     * during kernel shutdown. The implementation MUST be
     * idempotent — a worker that crashed mid-request and is
     * being recycled may invoke shutdown() twice.
     */
    public function shutdown(ContainerInterface $container): void;
}
