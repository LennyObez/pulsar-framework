<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Evidence;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * An immutable record of evidence that a control is implemented.
 *
 * Evidence records are timestamped and optionally signed for tamper detection.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EvidenceRecord
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public string $controlId,
        public string $type,
        public string $description,
        public array $data,
        public DateTimeImmutable $collectedAt,
        public ?string $signature = null,
    ) {}
}
