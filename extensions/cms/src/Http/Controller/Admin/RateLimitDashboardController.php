<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Http\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\GateInterface;
use Pulsar\Extension\Cms\Settings\SettingsServiceInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Observability\Metrics\LabelSet;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\View\Engine\TemplateEngineInterface;

use function array_slice;
use function arsort;
use function is_int;
use function is_string;
use function max;
use function round;

/**
 * Admin dashboard for monitoring and configuring rate limiting.
 *
 * Displays request/rejection counts per endpoint, top IPs hitting limits,
 * and allows inline editing of rate limit configuration stored in CMS settings.
 */
#[Internal(reason: 'CMS admin controller; implementation detail')]
final readonly class RateLimitDashboardController extends AbstractAdminController
{
    private const string SETTINGS_GROUP = 'rate_limits';

    /**
     * Default rate limit configuration for common endpoint patterns.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    private const array DEFAULT_ENDPOINTS = [
        '/api/cms/comments' => ['limit' => 30, 'window' => 60],
        '/api/cms/forms' => ['limit' => 10, 'window' => 60],
        '/api/cms/auth/login' => ['limit' => 5, 'window' => 300],
        '/api/cms/auth/register' => ['limit' => 3, 'window' => 600],
        '/api/cms/media/upload' => ['limit' => 20, 'window' => 60],
        '/api/cms/search' => ['limit' => 60, 'window' => 60],
        '/api/cms/export' => ['limit' => 5, 'window' => 300],
    ];

    public function __construct(
        private SettingsServiceInterface $settings,
        ?GateInterface $gate = null,
        private ?MetricRegistry $metricRegistry = null,
        ?TemplateEngineInterface $templateEngine = null,
    ) {
        parent::__construct($templateEngine, $gate);
    }

    /**
     * Render the rate limiting dashboard.
     *
     * Shows endpoint configurations, request/rejection metrics, and top IPs.
     */
    public function dashboard(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.manage');

        $endpoints = $this->loadEndpointConfigs();
        $metrics = $this->collectMetrics($endpoints);
        $topIps = $this->collectTopIps();

        $totalRequests = 0;
        $totalRejections = 0;

        foreach ($metrics as $metric) {
            $totalRequests += $metric['requests'];
            $totalRejections += $metric['rejections'];
        }

        $rejectionRate = $totalRequests > 0
            ? round((float) $totalRejections / (float) $totalRequests * 100.0, 1)
            : 0.0;

        $data = [
            'endpoints' => $metrics,
            'topIps' => $topIps,
            'summary' => [
                'total_requests' => $totalRequests,
                'total_rejections' => $totalRejections,
                'rejection_rate' => $rejectionRate,
            ],
        ];

        return $this->respondWithView($request, 'admin.rate-limits.dashboard', $data);
    }

    /**
     * Update rate limit configuration for a specific endpoint.
     *
     * Stores the limit and window values in CMS settings for runtime use.
     */
    public function updateLimit(ServerRequestInterface $request): Response
    {
        $identity = $this->requireIdentity($request);
        $this->authorize($identity, 'cms.settings.manage');

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var string $endpoint */
        $endpoint = is_string($body['endpoint'] ?? null) ? $body['endpoint'] : '';

        /** @var int|string $rawLimit */
        $rawLimit = $body['limit'] ?? 0;
        $limit = (int) $rawLimit;

        /** @var int|string $rawWindow */
        $rawWindow = $body['window'] ?? 0;
        $window = (int) $rawWindow;

        if ($endpoint === '') {
            return Response::json(['error' => 'Endpoint pattern is required'], 400);
        }

        if ($limit < 1 || $limit > 10000) {
            return Response::json(['error' => 'Limit must be between 1 and 10,000'], 400);
        }

        if ($window < 1 || $window > 86400) {
            return Response::json(['error' => 'Window must be between 1 and 86,400 seconds'], 400);
        }

        $this->settings->set(
            self::SETTINGS_GROUP,
            'endpoint:' . $endpoint . ':limit',
            $limit,
            null,
            'Rate limit updated via admin dashboard',
        );

        $this->settings->set(
            self::SETTINGS_GROUP,
            'endpoint:' . $endpoint . ':window',
            $window,
            null,
            'Rate limit window updated via admin dashboard',
        );

        return Response::json([
            'endpoint' => $endpoint,
            'limit' => $limit,
            'window' => $window,
        ]);
    }

    /**
     * Load endpoint configurations from CMS settings, falling back to defaults.
     *
     * @return array<string, array{limit: int, window: int}>
     */
    private function loadEndpointConfigs(): array
    {
        $configs = [];

        foreach (self::DEFAULT_ENDPOINTS as $endpoint => $defaults) {
            /** @var mixed $storedLimit */
            $storedLimit = $this->settings->get(self::SETTINGS_GROUP, 'endpoint:' . $endpoint . ':limit');
            /** @var mixed $storedWindow */
            $storedWindow = $this->settings->get(self::SETTINGS_GROUP, 'endpoint:' . $endpoint . ':window');

            $configs[$endpoint] = [
                'limit' => is_int($storedLimit) ? max(1, $storedLimit) : $defaults['limit'],
                'window' => is_int($storedWindow) ? max(1, $storedWindow) : $defaults['window'],
            ];
        }

        return $configs;
    }

    /**
     * Collect request and rejection metrics per endpoint.
     *
     * Reads from the MetricRegistry when available, otherwise returns zeros.
     *
     * @param array<string, array{limit: int, window: int}> $endpoints
     *
     * @return list<array{endpoint: string, limit: int, window: int, requests: int, rejections: int, current_rate: float}>
     */
    private function collectMetrics(array $endpoints): array
    {
        $metrics = [];

        foreach ($endpoints as $endpoint => $config) {
            $requests = 0;
            $rejections = 0;

            if ($this->metricRegistry !== null) {
                $labels = new LabelSet(['endpoint' => $endpoint]);

                if ($this->metricRegistry->has('rate_limit_requests_total')) {
                    $counter = $this->metricRegistry->counter('rate_limit_requests_total');
                    $requests = (int) $counter->value($labels);
                }

                if ($this->metricRegistry->has('rate_limit_rejections_total')) {
                    $counter = $this->metricRegistry->counter('rate_limit_rejections_total');
                    $rejections = (int) $counter->value($labels);
                }
            }

            $currentRate = $config['window'] > 0 && $requests > 0
                ? round($requests / $config['window'], 2)
                : 0.0;

            $metrics[] = [
                'endpoint' => $endpoint,
                'limit' => $config['limit'],
                'window' => $config['window'],
                'requests' => $requests,
                'rejections' => $rejections,
                'current_rate' => $currentRate,
            ];
        }

        return $metrics;
    }

    /**
     * Collect top 10 IPs hitting rate limits.
     *
     * Reads from the MetricRegistry when available, otherwise returns
     * an empty array. IPs are stored as hashes for privacy.
     *
     * @return list<array{ip_hash: string, rejections: int}>
     */
    private function collectTopIps(): array
    {
        if ($this->metricRegistry === null || !$this->metricRegistry->has('rate_limit_rejections_by_ip')) {
            return [];
        }

        $counter = $this->metricRegistry->counter('rate_limit_rejections_by_ip');
        $values = $counter->values();

        // Sort by rejection count descending
        arsort($values);

        // Take top 10
        $topValues = array_slice($values, 0, 10, true);

        $result = [];

        foreach ($topValues as $labelKey => $count) {
            $result[] = [
                'ip_hash' => $labelKey,
                'rejections' => (int) $count,
            ];
        }

        return $result;
    }
}
