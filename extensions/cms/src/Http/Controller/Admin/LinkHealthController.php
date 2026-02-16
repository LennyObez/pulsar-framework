<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Seo\LinkHealthCheck;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_map;
use function count;
use function is_int;
use function max;
use function min;

/**
 * Admin controller for link health monitoring.
 *
 * Provides a broken links report and the ability to trigger
 * a full link health check across all published content.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class LinkHealthController
{
    use RendersAdminView;

    public function __construct(
        private LinkHealthServiceInterface $linkHealthService,
        private ?GateInterface $gate = null,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * Display broken links report with pagination and filters.
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.view');

        $params = $request->getQueryParams();
        $page = max(1, is_int($params['page'] ?? null) ? $params['page'] : 1);
        $perPage = min(100, max(1, is_int($params['per_page'] ?? null) ? $params['per_page'] : 50));

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $brokenLinks = $this->linkHealthService->getBrokenLinks($tenantId, $page, $perPage);

        $data = [
            'links' => array_map(static fn(LinkHealthCheck $check) => [
                'id' => $check->id,
                'source_content_id' => $check->sourceContentId,
                'source_locale' => $check->sourceLocale,
                'target_url' => $check->targetUrl,
                'is_broken' => $check->isBroken,
                'is_redirected' => $check->isRedirected,
                'http_status_code' => $check->httpStatusCode,
                'last_checked_at' => $check->lastCheckedAt->format('c'),
                'created_at' => $check->createdAt->format('c'),
            ], $brokenLinks),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
            ],
        ];

        return $this->respondWithView($request, 'admin.seo.link-health', $data);
    }

    /**
     * Trigger a full link health check across all published content.
     */
    public function runCheck(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.seo.manage');

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $results = $this->linkHealthService->checkAll($tenantId);

        $brokenCount = 0;
        $redirectedCount = 0;

        foreach ($results as $check) {
            if ($check->isBroken) {
                $brokenCount++;
            }
            if ($check->isRedirected) {
                $redirectedCount++;
            }
        }

        return Response::json([
            'total_checked' => count($results),
            'broken' => $brokenCount,
            'redirected' => $redirectedCount,
            'healthy' => count($results) - $brokenCount - $redirectedCount,
        ]);
    }

}
