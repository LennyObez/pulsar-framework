<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Result of a Peppol AS4 transmission.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PeppolTransmissionResult
{
    public function __construct(
        public string $transmissionId,
        public PeppolTransmissionStatus $status,
        public DateTimeImmutable $timestamp,
        public string $rawResponse,
    ) {}
}
