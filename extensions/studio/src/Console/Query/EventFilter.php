<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Query;

use function array_map;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventType;

/**
 * Readonly DTO representing all filter criteria for event queries.
 */
#[Internal]
final readonly class EventFilter
{
    /**
     * @param list<EventType> $eventTypes
     */
    public function __construct(
        public array $eventTypes = [],
        public ?string $requestId = null,
        public ?string $traceId = null,
        public ?string $jobId = null,
        public ?string $tenantHash = null,
        public ?int $sinceUs = null,
        public ?int $untilUs = null,
        public ?int $sinceId = null,
        public ?string $search = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->eventTypes === []
            && $this->requestId === null
            && $this->traceId === null
            && $this->jobId === null
            && $this->tenantHash === null
            && $this->sinceUs === null
            && $this->untilUs === null
            && $this->sinceId === null
            && $this->search === null;
    }

    /**
     * Convert to the array format expected by SqliteEventStore::query().
     *
     * @return array<string, mixed>
     */
    public function toStoreFilters(): array
    {
        $filters = [];

        if ($this->eventTypes !== []) {
            $filters['event_type'] = array_map(
                static fn(EventType $t): string => $t->value,
                $this->eventTypes,
            );
        }

        if ($this->requestId !== null) {
            $filters['request_id'] = $this->requestId;
        }

        if ($this->traceId !== null) {
            $filters['trace_id'] = $this->traceId;
        }

        if ($this->jobId !== null) {
            $filters['job_id'] = $this->jobId;
        }

        if ($this->tenantHash !== null) {
            $filters['tenant_hash'] = $this->tenantHash;
        }

        if ($this->sinceUs !== null) {
            $filters['since_us'] = $this->sinceUs;
        }

        if ($this->untilUs !== null) {
            $filters['until_us'] = $this->untilUs;
        }

        if ($this->sinceId !== null) {
            $filters['since_id'] = $this->sinceId;
        }

        return $filters;
    }
}
