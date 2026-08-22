<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Storage;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Readonly DTO representing an immutable transition log entry.
 *
 * Maps to the `workflow_transitions` table schema. Records are append-only
 * and never updated or deleted, forming a complete audit trail.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class TransitionRecord
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $instanceId,
        public string $fromState,
        public string $toState,
        public string $transitionName,
        public string $actor,
        public ?string $reason,
        public array $metadata,
        public int $instanceVersion,
        public DateTimeImmutable $createdAt,
    ) {}
}
