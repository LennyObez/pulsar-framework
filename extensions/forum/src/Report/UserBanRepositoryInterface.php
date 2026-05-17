<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Report;

use Pulsar\Api\Api;
use Pulsar\Api\Pagination\PaginationResult;

/**
 * Repository interface for user ban records.
 * @api
 */
#[Api(since: '1.0.0')]
interface UserBanRepositoryInterface
{
    public function findById(string $id): ?UserBan;

    /**
     * Find the currently active ban for a user (if any).
     */
    public function findActiveByUser(string $userId): ?UserBan;

    /**
     * Find all ban records for a user (including revoked and expired).
     *
     * @return list<UserBan>
     */
    public function findByUser(string $userId): array;

    /**
     * Find all currently active bans with pagination.
     *
     * @return PaginationResult<UserBan>
     */
    public function findActive(int $page = 1, int $perPage = 20): PaginationResult;

    public function save(UserBan $ban): void;
}
