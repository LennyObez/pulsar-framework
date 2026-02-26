<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Internal\Transport;

use Pulsar\Api\Internal;

/**
 * Contract for sending serialized OTLP payloads to a collector.
 */
#[Internal]
interface OtlpTransportInterface
{
    public function send(string $path, string $protobufPayload): TransportResult;
}
