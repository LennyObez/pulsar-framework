<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * A signed invoice containing the XML, cryptographic signature, and verification key.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SignedInvoice
{
    public function __construct(
        public string $xml,
        public string $signatureHex,
        public string $publicKeyHex,
        public DateTimeImmutable $signedAt,
    ) {}
}
