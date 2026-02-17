<?php

declare(strict_types=1);

namespace Pulsar\Extension\Eidas\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of an electronic seal verification.
 */
#[Api(since: '1.0.0')]
final readonly class SealInfo
{
    public function __construct(
        public bool $valid,
        public string $organizationName,
        public string $organizationId,
        public SignatureFormat $format,
        public bool $isQualified,
        public DateTimeImmutable $sealedAt,
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
            'organization_name' => $this->organizationName,
            'organization_id' => $this->organizationId,
            'format' => $this->format->value,
            'is_qualified' => $this->isQualified,
            'sealed_at' => $this->sealedAt->format('Y-m-d\TH:i:s.uP'),
            'reason' => $this->reason,
        ];
    }
}
