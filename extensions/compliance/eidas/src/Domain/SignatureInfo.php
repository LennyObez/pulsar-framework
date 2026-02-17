<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a signature verification operation.
 *
 * Contains the verification status, signer identity, signature format,
 * and whether the signature qualifies as a QES (Qualified Electronic Signature).
 */
#[Api(since: '1.0.0')]
final readonly class SignatureInfo
{
    /**
     * @param list<string> $trustChain Certificate chain subjects from signer to root
     */
    public function __construct(
        public bool $valid,
        public string $signerName,
        public string $signerIdentifier,
        public SignatureFormat $format,
        public bool $isQualified,
        public DateTimeImmutable $signedAt,
        public array $trustChain = [],
        public string $reason = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'signer_name' => $this->signerName,
            'signer_identifier' => $this->signerIdentifier,
            'format' => $this->format->value,
            'is_qualified' => $this->isQualified,
            'signed_at' => $this->signedAt->format('Y-m-d\TH:i:s.uP'),
            'trust_chain' => $this->trustChain,
            'reason' => $this->reason,
        ];
    }
}
