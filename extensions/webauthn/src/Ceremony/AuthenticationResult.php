<?php

declare(strict_types=1);

namespace Pulsar\Extension\WebAuthn\Ceremony;

use Pulsar\Api\Api;

/**
 * Result of a successful WebAuthn authentication ceremony.
 */
#[Api(since: '1.0.0')]
final readonly class AuthenticationResult
{
    public function __construct(
        public string $credentialId,
        public string $userId,
        public int $signatureCounter,
        public bool $userVerified,
    ) {}
}
