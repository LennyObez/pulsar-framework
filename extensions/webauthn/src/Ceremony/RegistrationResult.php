<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Ceremony;

use Pulsar\Api\Api;
use Pulsar\Extension\WebAuthn\PublicKey\CredentialSource;

/**
 * Result of a successful WebAuthn registration ceremony.
 */
#[Api(since: '1.0.0')]
final readonly class RegistrationResult
{
    public function __construct(
        public CredentialSource $credential,
        public string $attestationFormat,
        public bool $isDiscoverable,
    ) {}
}
