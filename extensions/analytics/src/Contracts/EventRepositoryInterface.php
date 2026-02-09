<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\CustomEvent;

/**
 * Persistence interface for custom event records.
 */
#[Api(since: '1.0.0')]
interface EventRepositoryInterface
{
    public function insert(CustomEvent $event): void;

    /**
     * @return list<CustomEvent>
     */
    public function findBySite(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $eventName = null,
        int $limit = 1000,
    ): array;

    /**
     * Delete events older than the given date.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(DateTimeImmutable $before): int;
}
