<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Api;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Forum\Service\LeaderboardServiceInterface;
use Pulsar\Http\Message\Response;

use function array_map;
use function in_array;
use function is_string;
use function max;
use function min;

/**
 * Public REST API controller for forum leaderboard.
 */
#[Internal(reason: 'Forum REST API controller — implementation detail')]
final readonly class LeaderboardController
{
    public function __construct(
        private LeaderboardServiceInterface $leaderboardService,
    ) {}

    /**
     * GET /api/v1/forum/leaderboard — Get top users by reputation.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        $period = is_string($params['period'] ?? null) ? $params['period'] : 'all';

        if (!in_array($period, ['all', 'month', 'week'], true)) {
            $period = 'all';
        }

        $limit = min(100, max(1, is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : 25));

        $ranked = $this->leaderboardService->getTopUsers($period, $limit);

        $data = array_map(static fn(array $entry) => [
            'rank' => $entry['rank'],
            'user_id' => $entry['profile']->userId,
            'reputation_score' => $entry['profile']->reputationScore,
            'reputation_level' => $entry['profile']->reputationLevel()->value,
            'post_count' => $entry['profile']->postCount,
            'thread_count' => $entry['profile']->threadCount,
            'member_since' => $entry['profile']->createdAt->format('c'),
        ], $ranked);

        return Response::json([
            'data' => $data,
            'period' => $period,
            'limit' => $limit,
        ]);
    }
}
