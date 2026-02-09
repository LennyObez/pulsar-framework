<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\Session;

/**
 * Persistence interface for session records.
 */
#[Api(since: '1.0.0')]
interface SessionRepositoryInterface
{
    /**
     * Find an active session for a visitor within the inactivity window.
     */
    public function findActiveByVisitor(string $siteId, string $visitorId, DateTimeImmutable $since): ?Session;

    public function save(Session $session): void;

    public function update(Session $session): void;

    /**
     * Delete sessions older than the given date.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(DateTimeImmutable $before): int;
}
