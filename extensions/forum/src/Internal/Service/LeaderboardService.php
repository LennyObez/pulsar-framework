<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Internal\Service;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Service\LeaderboardServiceInterface;

use function max;
use function min;

/**
 * Leaderboard service — ranks forum users by reputation for various time periods.
 *
 * For 'all' periods, delegates to ForumProfileRepository::findTopContributors().
 * For 'month' and 'week' periods, queries reputation change data directly
 * with date filters and joins to forum profiles.
 */
#[Internal(reason: 'Use LeaderboardServiceInterface for public API')]
final readonly class LeaderboardService implements LeaderboardServiceInterface
{
    private const string SQL_TOP_PROFILES_BY_RECENT_ACTIVITY = <<<'SQL'
        SELECT p.*, (
            SELECT COUNT(*)
            FROM forum_posts fp
            WHERE fp.author_id = p.user_id
                AND fp.created_at >= :since
                AND fp.deleted_at IS NULL
        ) + (
            SELECT COUNT(*)
            FROM forum_threads ft
            WHERE ft.author_id = p.user_id
                AND ft.created_at >= :since
                AND ft.deleted_at IS NULL
        ) AS recent_activity
        FROM forum_profiles p
        WHERE p.is_banned = false
        HAVING recent_activity > 0
        ORDER BY recent_activity DESC, p.reputation_score DESC
        LIMIT :limit
        SQL;

    public function __construct(
        private ForumProfileRepositoryInterface $profiles,
        private ConnectionInterface $connection,
    ) {}

    public function getTopUsers(string $period = 'all', int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        if ($period === 'all') {
            return $this->getAllTimeLeaderboard($limit);
        }

        $since = $this->periodToDate($period);

        return $this->getRecentLeaderboard($since, $limit);
    }

    /**
     * @return list<array{profile: ForumProfile, rank: int}>
     */
    private function getAllTimeLeaderboard(int $limit): array
    {
        $result = $this->profiles->findTopContributors(1, $limit);
        $ranked = [];
        $rank = 1;

        foreach ($result->items as $profile) {
            $ranked[] = ['profile' => $profile, 'rank' => $rank];
            $rank++;
        }

        return $ranked;
    }

    /**
     * @return list<array{profile: ForumProfile, rank: int}>
     */
    private function getRecentLeaderboard(DateTimeImmutable $since, int $limit): array
    {
        $result = $this->connection->query(self::SQL_TOP_PROFILES_BY_RECENT_ACTIVITY, [
            'since' => $since->format('c'),
            'limit' => $limit,
        ]);

        $ranked = [];
        $rank = 1;

        foreach ($result->map(self::hydrateProfile(...)) as $profile) {
            $ranked[] = ['profile' => $profile, 'rank' => $rank];
            $rank++;
        }

        return $ranked;
    }

    private function periodToDate(string $period): DateTimeImmutable
    {
        $now = new DateTimeImmutable();

        return match ($period) {
            'week' => $now->modify('-7 days'),
            default => $now->modify('-30 days'),
        };
    }

    private static function hydrateProfile(Row $row): ForumProfile
    {
        return new ForumProfile(
            id: $row->getString('id'),
            tenantId: $row->getNullableString('tenant_id'),
            userId: $row->getString('user_id'),
            reputationScore: $row->getInt('reputation_score'),
            postCount: $row->getInt('post_count'),
            threadCount: $row->getInt('thread_count'),
            isBanned: $row->getBool('is_banned'),
            banReason: $row->getNullableString('ban_reason'),
            bannedAt: self::toDateTime($row->getNullableString('banned_at')),
            banExpiresAt: self::toDateTime($row->getNullableString('ban_expires_at')),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    private static function toDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        return new DateTimeImmutable($value);
    }
}
