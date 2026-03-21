<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Upgrade;

use Pulsar\Api\Api;
use Pulsar\Http\Message\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Response signaling a connection upgrade (WebSocket handshake).
 *
 * When the runtime detects an UpgradeResponse, it sends the 101 handshake
 * and transfers socket ownership to the UpgradeHandlerInterface.
 * @api
 */
#[Api(since: '1.0.0')]
final class UpgradeResponse extends Response
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly UpgradeHandlerInterface $handler,
        array $headers = [],
    ) {
        parent::__construct(
            statusCode: ResponseStatus::SwitchingProtocols->value,
            headers: $headers,
        );
    }
}
