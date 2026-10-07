<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Represents a qualified timestamp token per RFC 3161.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TimestampToken
{
    public function __construct(
        public string $tokenId,
        public string $dataHash,
        public string $hashAlgorithm,
        public DateTimeImmutable $timestamp,
        public string $tsaName,
        public bool $isQualified,
        public string $encodedToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'token_id' => $this->tokenId,
            'data_hash' => $this->dataHash,
            'hash_algorithm' => $this->hashAlgorithm,
            'timestamp' => $this->timestamp->format('Y-m-d\TH:i:s.uP'),
            'tsa_name' => $this->tsaName,
            'is_qualified' => $this->isQualified,
        ];
    }
}
