<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\Attestation;

use Pulsar\Api\Api;

/**
 * Result of attestation statement verification.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AttestationResult
{
    public function __construct(
        public bool $verified,
        public string $format,
        public AttestationTrustLevel $trustLevel,
        public ?string $aaguid = null,
    ) {}
}
