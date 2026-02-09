<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Search\DateRange;
use Pulsar\Extension\Cms\Search\SearchServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\View\Engine\TemplateEngineInterface;

use function is_string;
use function max;

/**
 * Admin controller for search analytics dashboard.
 *
 * Provides aggregated search analytics data for a configurable date range:
 * top queries, zero-result queries, click-through rates, and totals.
 */
#[Internal(reason: 'CMS admin controller — implementation detail')]
final readonly class SearchAnalyticsController
{
    use RendersAdminView;

    public function __construct(
        private SearchServiceInterface $searchService,
        private GateInterface $gate,
        private ?TemplateEngineInterface $templateEngine = null,
    ) {}

    /**
     * Display search analytics for a date range.
     *
     * Query params:
     * - from: ISO 8601 date (default: 30 days ago)
     * - to: ISO 8601 date (default: now)
     */
    public function index(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.analytics.view');

        $params = $request->getQueryParams();

        $fromStr = is_string($params['from'] ?? null) ? $params['from'] : null;
        $toStr = is_string($params['to'] ?? null) ? $params['to'] : null;

        $now = new DateTimeImmutable();

        $to = $now;
        if ($toStr !== null) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $toStr);
            if ($parsed === false) {
                return Response::json(['error' => 'Invalid "to" date format. Expected Y-m-d.'], 400);
            }
            $to = $parsed;
        }

        $from = $to->modify('-30 days');
        if ($fromStr !== null) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $fromStr);
            if ($parsed === false) {
                return Response::json(['error' => 'Invalid "from" date format. Expected Y-m-d.'], 400);
            }
            $from = $parsed;
        }

        // Validate date range: from must be before to
        if ($from > $to) {
            return Response::json(['error' => '"from" date must be before or equal to "to" date.'], 400);
        }

        // Reject unreasonably large ranges (> 366 days)
        $daysDiff = max(1, (int) $from->diff($to)->days);
        if ($daysDiff > 366) {
            return Response::json(['error' => 'Date range must not exceed 366 days.'], 400);
        }

        /** @var string|null $tenantId */
        $tenantId = $request->getAttribute('tenant_id');

        $analytics = $this->searchService->getAnalytics(
            new DateRange($from, $to),
            $tenantId,
        );

        $data = [
            'date_range' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
            'total_searches' => $analytics->totalSearches,
            'unique_queries' => $analytics->uniqueQueries,
            'top_queries' => $analytics->topQueries,
            'zero_result_queries' => $analytics->zeroResultQueries,
            'click_through_rates' => $analytics->clickThroughRates,
        ];

        return $this->respondWithView($request, 'admin.search-analytics.index', $data);
    }
}
