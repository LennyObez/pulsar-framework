<?php

declare(strict_types=1);

namespace Pulsar\Saga\Port;

use Pulsar\Api\Api;

/**
 * Port for dispatching commands to local or remote handlers.
 *
 * Saga steps use this to execute their forward and compensation actions.
 * Implementations may delegate to a local command bus, a message queue,
 * or an RPC client depending on the deployment topology.
 * @api
 */
#[Api(since: '1.0.0')]
interface CommandBusPort
{
    /**
     * Dispatch a command to its handler.
     *
     * @param class-string         $actionClass     The action/handler class
     * @param array<string, mixed> $context          Action context/parameters
     * @param string|null          $idempotencyKey   Idempotency key for safe retries
     *
     * @return array<string, mixed> Action result
     */
    public function dispatch(string $actionClass, array $context, ?string $idempotencyKey = null): array;
}
