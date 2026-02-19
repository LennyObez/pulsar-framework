<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Extension\Cms\Dashboard\DashboardService;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Extension\Cms\Workflow\EditorialWorkflowServiceInterface;
use Pulsar\Http\Message\Response;
use RuntimeException;

use function array_keys;
use function array_map;
use function array_slice;
use function count;

/**
 * Admin CMS dashboard controller with widget data.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class DashboardController
{
    public function __construct(
        private DashboardService $dashboardService,
        private EditorialWorkflowServiceInterface $workflowService,
        private SettingsServiceInterface $settingsService,
        private GateInterface $gate,
    ) {}

    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.dashboard.view');

        $pendingReviews = $this->workflowService->getPendingReviews();
        $settings = $this->settingsService->getAll();

        $widgets = $this->dashboardService->collectWidgetData();

        $widgets['pending_reviews'] = [
            'data' => [
                'count' => count($pendingReviews),
                'items' => array_map(static fn($r) => [
                    'id' => $r->id,
                    'content_id' => $r->contentId,
                    'requested_by' => $r->requestedBy,
                    'created_at' => $r->createdAt->format('c'),
                ], array_slice($pendingReviews, 0, 5)),
            ],
            'template' => 'dashboard/widgets/pending-reviews',
        ];

        $widgets['site_settings'] = [
            'data' => [
                'groups' => array_keys($settings),
            ],
            'template' => 'dashboard/widgets/site-settings',
        ];

        return Response::json([
            'widgets' => $widgets,
        ]);
    }

    private function requireIdentity(ServerRequestInterface $request): IdentityInterface
    {
        /** @var IdentityInterface|null $identity */
        $identity = $request->getAttribute('identity');

        if ($identity === null || !$identity->isAuthenticated()) {
            throw new RuntimeException('Authentication required');
        }

        return $identity;
    }

    private function authorize(IdentityInterface $identity, string $permission): void
    {
        if ($this->gate->denies($identity, $permission)) {
            throw new RuntimeException('Permission denied');
        }
    }
}
