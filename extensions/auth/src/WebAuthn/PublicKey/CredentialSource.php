<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\WebAuthn\PublicKey;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * WebAuthn credential source (public key, counter, transports).
 *
 * Represents a registered WebAuthn credential linked to a user account.
 */
#[Api(since: '1.0.0')]
final readonly class CredentialSource
{
    /**
     * @param list<string> $transports Supported authenticator transports (usb, nfc, ble, internal)
     */
    public function __construct(
        public string $credentialId,
        public string $userId,
        public string $publicKeyPem,
        public int $signatureCounter,
        public string $attestationFormat,
        public array $transports,
        public bool $discoverable,
        public string $aaguid,
        public DateTimeImmutable $createdAt,
    ) {}
}
