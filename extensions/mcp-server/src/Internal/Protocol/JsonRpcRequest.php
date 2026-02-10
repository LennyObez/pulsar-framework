<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Protocol;

use Pulsar\Api\Internal;

/**
 * Parsed JSON-RPC 2.0 request.
 *
 * Notifications have a null id. Requests always have a non-null id.
 */
#[Internal]
final readonly class JsonRpcRequest
{
    /**
     * @param string|int|null $id Request ID (null for notifications)
     * @param string $method JSON-RPC method name
     * @param array<string, mixed> $params Method parameters
     */
    public function __construct(
        public string|int|null $id,
        public string $method,
        public array $params,
    ) {}

    /**
     * Whether this is a notification (no response expected).
     */
    public function isNotification(): bool
    {
        return $this->id === null;
    }
}
