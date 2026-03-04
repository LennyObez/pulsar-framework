<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Config;

use Pulsar\Api\Api;

/**
 * OTLP transport protocol.
 */
#[Api(since: '1.0.0')]
enum OtlpProtocol: string
{
    case HttpProtobuf = 'http/protobuf';
    case Grpc = 'grpc';
}
