<?php

declare(strict_types=1);

namespace Pulsar\Security\DigitalSignature;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Immutable DTO containing metadata about a verified electronic signature.
 */
#[Api(since: '1.0.0')]
final readonly class SignatureInfo
{
    /**
     * @param bool                  $valid          Whether the signature is cryptographically valid
     * @param SignatureFormat       $format         The signature format used
     * @param string                $signerSubject  Distinguished name or identifier of the signer
     * @param DateTimeImmutable     $signedAt       Timestamp when the signature was created
     * @param DateTimeImmutable|null $expiresAt     Certificate expiration, if available
     * @param bool                  $qualified      Whether this is a Qualified Electronic Signature (QES)
     * @param list<string>          $trustChain     Certificate chain subjects from signer to trust anchor
     * @param string                $reason         Failure reason if not valid, empty otherwise
     */
    public function __construct(
        public bool $valid,
        public SignatureFormat $format,
        public string $signerSubject,
        public DateTimeImmutable $signedAt,
        public ?DateTimeImmutable $expiresAt = null,
        public bool $qualified = false,
        public array $trustChain = [],
        public string $reason = '',
    ) {}
}
