<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Service;

use Pulsar\Api\Api;
use Pulsar\Extension\Forum\Profile\ForumProfile;

/**
 * Service for computing forum leaderboard rankings.
 * @api
 */
#[Api(since: '1.0.0')]
interface LeaderboardServiceInterface
{
    /**
     * Get the top users ranked by reputation for a given time period.
     *
     * @param string $period Time period filter: 'all', 'month', or 'week'
     * @param int $limit Maximum number of users to return (capped at 100)
     *
     * @return list<array{profile: ForumProfile, rank: int}>
     */
    public function getTopUsers(string $period = 'all', int $limit = 25): array;
}
