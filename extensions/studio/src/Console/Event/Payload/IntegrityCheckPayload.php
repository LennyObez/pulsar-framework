<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

/**
 * Integrity verification event payload for Studio Console.
 *
 * Records the result of a file integrity check for monitoring
 * and auditing in the developer dashboard.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class IntegrityCheckPayload implements ConsoleEvent
{
    public function __construct(
        public bool $passed,
        public int $verified,
        public int $modified,
        public int $missing,
        public int $added,
        public int $checkedAt,
    ) {}

    public function eventType(): EventType
    {
        return EventType::IntegrityCheck;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'verified' => $this->verified,
            'modified' => $this->modified,
            'missing' => $this->missing,
            'added' => $this->added,
            'checked_at' => $this->checkedAt,
        ];
    }
}
