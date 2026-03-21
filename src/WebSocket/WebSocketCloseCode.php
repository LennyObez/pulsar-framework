<?php

declare(strict_types=1);

namespace Pulsar\WebSocket;

use Pulsar\Api\Api;

/**
 * WebSocket close status codes (RFC 6455 Section 7.4.1).
 * @api
 */
#[Api(since: '1.0.0')]
enum WebSocketCloseCode: int
{
    case Normal = 1000;
    case GoingAway = 1001;
    case ProtocolError = 1002;
    case UnsupportedData = 1003;
    case NoStatusReceived = 1005;
    case AbnormalClosure = 1006;
    case InvalidPayload = 1007;
    case PolicyViolation = 1008;
    case MessageTooBig = 1009;
    case MandatoryExtension = 1010;
    case InternalError = 1011;
    case ServiceRestart = 1012;
    case TryAgainLater = 1013;
}
