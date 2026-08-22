<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\Session;

/**
 * Persistence interface for session records.
 * @api
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
     * Find all sessions for a specific visitor (GDPR data subject access).
     *
     * @return list<Session>
     */
    public function findByVisitorId(string $visitorId, int $limit = 10000): array;

    /**
     * Delete sessions older than the given date.
     *
     * @return int Number of deleted rows
     */
    public function deleteOlderThan(DateTimeImmutable $before): int;

    /**
     * Delete all sessions for a specific visitor (GDPR right to erasure).
     *
     * @return int Number of deleted rows
     */
    public function deleteByVisitorId(string $visitorId): int;
}
