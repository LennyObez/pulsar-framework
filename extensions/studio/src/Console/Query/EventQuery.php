<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Query;

use NoDiscard;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;

use function array_values;
use function max;

/**
 * Fluent builder for querying Studio events.
 */
#[Internal]
final class EventQuery
{
    /** @var list<EventType> */
    private array $eventTypes = [];

    private ?string $requestId = null;
    private ?string $traceId = null;
    private ?string $jobId = null;
    private ?string $tenantHash = null;
    private ?int $sinceUs = null;
    private ?int $untilUs = null;
    private ?int $sinceId = null;
    private ?string $search = null;
    private int $limit = 50;
    private int $offset = 0;

    public function __construct(
        private readonly EventStoreInterface $store,
    ) {}

    public function type(EventType ...$types): self
    {
        $this->eventTypes = array_values([...$this->eventTypes, ...$types]);
        return $this;
    }

    public function requestId(string $requestId): self
    {
        $this->requestId = $requestId;
        return $this;
    }

    public function traceId(string $traceId): self
    {
        $this->traceId = $traceId;
        return $this;
    }

    public function jobId(string $jobId): self
    {
        $this->jobId = $jobId;
        return $this;
    }

    public function tenantHash(string $tenantHash): self
    {
        $this->tenantHash = $tenantHash;
        return $this;
    }

    public function since(int $timestampUs): self
    {
        $this->sinceUs = $timestampUs;
        return $this;
    }

    public function until(int $timestampUs): self
    {
        $this->untilUs = $timestampUs;
        return $this;
    }

    public function sinceId(int $id): self
    {
        $this->sinceId = $id;
        return $this;
    }

    public function search(string $term): self
    {
        $this->search = $term;
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);
        return $this;
    }

    public function page(int $page, int $perPage = 50): self
    {
        $this->limit = max(1, $perPage);
        $this->offset = max(0, ($page - 1)) * $this->limit;
        return $this;
    }

    public function filter(): EventFilter
    {
        return new EventFilter(
            eventTypes: $this->eventTypes,
            requestId: $this->requestId,
            traceId: $this->traceId,
            jobId: $this->jobId,
            tenantHash: $this->tenantHash,
            sinceUs: $this->sinceUs,
            untilUs: $this->untilUs,
            sinceId: $this->sinceId,
            search: $this->search,
        );
    }

    #[NoDiscard]
    public function get(): EventQueryResult
    {
        $filter = $this->filter();
        $storeFilters = $filter->toStoreFilters();

        $items = $this->store->query($storeFilters, $this->limit, $this->offset);
        $total = $this->store->count($storeFilters);

        return new EventQueryResult(
            items: $items,
            total: $total,
            limit: $this->limit,
            offset: $this->offset,
        );
    }
}
