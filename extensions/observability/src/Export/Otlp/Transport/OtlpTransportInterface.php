<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Export\Otlp\Transport;

use Pulsar\Api\Internal;

/**
 * Contract for sending serialized OTLP payloads to a collector.
 */
#[Internal]
interface OtlpTransportInterface
{
    public function send(string $path, string $protobufPayload): TransportResult;
}
