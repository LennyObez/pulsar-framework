<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Forum\Domain\ReportStatus;
use Pulsar\Extension\Forum\Profile\ForumProfile;
use Pulsar\Extension\Forum\Profile\ForumProfileRepositoryInterface;
use Pulsar\Extension\Forum\Report\ThreadReportRepositoryInterface;
use Pulsar\Extension\Forum\Thread\Thread;
use Pulsar\Extension\Forum\Thread\ThreadRepositoryInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;

/**
 * Admin dashboard controller: overview stats and recent activity.
 */
#[Internal(reason: 'Forum admin controller; implementation detail')]
final readonly class DashboardController
{
    use RendersAdminView;
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private ThreadRepositoryInterface $threadRepository,
        private ThreadReportRepositoryInterface $reportRepository,
        private ForumProfileRepositoryInterface $profileRepository,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * GET /admin/forum: Forum dashboard with overview stats.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'forum.admin.dashboard');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $recentThreads = $this->threadRepository->findRecent(1, 10, $tenantId);
        $pendingReports = $this->reportRepository->findByStatus(ReportStatus::Pending, 1, 5, $tenantId);
        $topContributors = $this->profileRepository->findTopContributors(1, 5, $tenantId);

        $data = [
            'recent_threads' => array_map(static fn(Thread $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'slug' => $t->slug,
                'author_id' => $t->authorId,
                'status' => $t->status->value,
                'reply_count' => $t->replyCount,
                'created_at' => $t->createdAt->format('c'),
            ], $recentThreads->items),
            'pending_reports_count' => $pendingReports->total,
            'top_contributors' => array_map(static fn(ForumProfile $p) => [
                'user_id' => $p->userId,
                'reputation_score' => $p->reputationScore,
                'post_count' => $p->postCount,
                'thread_count' => $p->threadCount,
            ], $topContributors->items),
            'stats' => [
                'total_threads' => $recentThreads->total,
            ],
        ];

        return $this->respondWithView($request, 'admin.forum.dashboard', $data);
    }
}
