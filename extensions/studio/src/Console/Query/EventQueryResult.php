<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Query;

use Pulsar\Api\Internal;

use function ceil;

/**
 * Readonly DTO representing a paginated query result.
 */
#[Internal]
final readonly class EventQueryResult
{
    /**
     * @param list<array<string, mixed>> $items
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {}
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function hasMore(): bool
    {
        return ($this->offset + $this->limit) < $this->total;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function page(): int
    {
        return $this->limit > 0 ? intdiv($this->offset, $this->limit) + 1 : 1;
    }
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function totalPages(): int
    {
        return $this->limit > 0 ? (int) ceil((float) $this->total / (float) $this->limit) : 1;
    }
}
