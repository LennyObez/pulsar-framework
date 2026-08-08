<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Tooling\Data\BrokenHash\WebSocket;

const WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

function acceptKey(string $clientKey): string
{
    return base64_encode(sha1($clientKey . WEBSOCKET_GUID, true));
}
