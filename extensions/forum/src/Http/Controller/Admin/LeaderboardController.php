<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Service\LeaderboardServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function in_array;
use function is_numeric;
use function is_string;
use function max;
use function min;

/**
 * Admin controller for the forum leaderboard with period selector.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class LeaderboardController
{
    use RendersAdminView;

    public function __construct(
        private LeaderboardServiceInterface $leaderboardService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum/leaderboard: Show leaderboard with period selector.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.leaderboard');

        $params = $request->getQueryParams();

        /** @var mixed $rawPeriod */
        $rawPeriod = $params['period'] ?? null;
        $period = is_string($rawPeriod) ? $rawPeriod : 'all';

        if (!in_array($period, ['all', 'month', 'week'], true)) {
            $period = 'all';
        }

        /** @var mixed $rawLimit */
        $rawLimit = $params['limit'] ?? null;
        $limit = min(100, max(1, is_numeric($rawLimit) ? (int) $rawLimit : 25));

        $ranked = $this->leaderboardService->getTopUsers($period, $limit);

        $data = [
            'data' => array_map(static fn(array $entry) => [
                'rank' => $entry['rank'],
                'user_id' => $entry['profile']->userId,
                'reputation_score' => $entry['profile']->reputationScore,
                'reputation_level' => $entry['profile']->reputationLevel()->value,
                'post_count' => $entry['profile']->postCount,
                'thread_count' => $entry['profile']->threadCount,
                'member_since' => $entry['profile']->createdAt->format('c'),
            ], $ranked),
            'period' => $period,
            'limit' => $limit,
        ];

        return $this->respondWithView($request, 'admin.forum.leaderboard.index', $data);
    }
}
