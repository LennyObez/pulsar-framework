<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Upgrade;

use Pulsar\Api\Api;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Response;
use Pulsar\Http\ResponseStatus;

/**
 * Response signaling a connection upgrade (WebSocket handshake).
 *
 * When the runtime detects an UpgradeResponse, it sends the 101 handshake
 * and transfers socket ownership to the UpgradeHandlerInterface.
 */
#[Api]
final readonly class UpgradeResponse extends Response
{
    public function __construct(
        public UpgradeHandlerInterface $handler,
        HeaderBag $headers = new HeaderBag(),
    ) {
        parent::__construct(
            body: '',
            status: ResponseStatus::SwitchingProtocols,
            headers: $headers,
        );
    }
}
