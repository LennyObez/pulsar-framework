<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Export;

use Pulsar\Extension\OpenTelemetry\Internal\Transport\OtlpTransportInterface;
use Pulsar\Extension\OpenTelemetry\Internal\Transport\TransportResult;

/**
 * Recording OTLP transport shared by the exporter and bridge tests.
 *
 * Deliberately in its own PSR-4 file rather than beside one of its consumers: a
 * helper declared inside another test file is only reachable once that file
 * happens to have been loaded, which holds under sequential execution and breaks
 * as soon as tests run in separate parallel workers.
 */
final class StubTransport implements OtlpTransportInterface
{
    /** @var list<string> */
    public array $sentPayloads = [];

    /** @var list<string> */
    public array $sentPaths = [];

    public function __construct(
        private readonly TransportResult $result = new TransportResult(
            success: true,
            httpStatus: 200,
            errorMessage: '',
            retryable: false,
        ),
    ) {}

    public function send(string $path, string $protobufPayload): TransportResult
    {
        $this->sentPaths[] = $path;
        $this->sentPayloads[] = $protobufPayload;

        return $this->result;
    }
}
