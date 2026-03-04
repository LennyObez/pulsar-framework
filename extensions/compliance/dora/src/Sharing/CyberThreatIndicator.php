<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dora\Sharing;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Cyber threat indicator for information sharing per DORA Article 45.
 *
 * Financial entities may exchange cyber threat information and intelligence
 * amongst themselves, including indicators of compromise, tactics, techniques,
 * and procedures.
 */
#[Api(since: '1.0.0')]
final readonly class CyberThreatIndicator
{
    /**
     * @param list<string> $affectedSectors  Financial sectors relevant to this threat
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $value,
        public string $source,
        public ThreatSeverity $severity,
        public DateTimeImmutable $observedAt,
        public ?string $description = null,
        public ?string $mitigation = null,
        public array $affectedSectors = [],
        public ?DateTimeImmutable $expiresAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'source' => $this->source,
            'severity' => $this->severity->value,
            'observed_at' => $this->observedAt->format('Y-m-d\TH:i:sP'),
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->mitigation !== null) {
            $data['mitigation'] = $this->mitigation;
        }

        if ($this->affectedSectors !== []) {
            $data['affected_sectors'] = $this->affectedSectors;
        }

        if ($this->expiresAt !== null) {
            $data['expires_at'] = $this->expiresAt->format('Y-m-d\TH:i:sP');
        }

        return $data;
    }
}
