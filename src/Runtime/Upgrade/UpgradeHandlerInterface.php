<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Upgrade;

use Pulsar\Api\Api;
use Socket;

/**
 * Contract for WebSocket/Upgrade connection handlers.
 *
 * Once the HTTP handshake completes, the connection leaves the HTTP pipeline
 * and becomes owned by the handler. The handler receives an UpgradeContext
 * (no container reference) exposing only safe persistent services.
 * @api
 */
#[Api(since: '1.0.0')]
interface UpgradeHandlerInterface
{
    /**
     * Handle the upgraded connection.
     *
     * @param Socket $socket The hijacked socket
     * @param UpgradeContext $context Safe persistent services only
     */
    public function handleUpgrade(Socket $socket, UpgradeContext $context): void;
}
