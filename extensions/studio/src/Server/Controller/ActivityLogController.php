<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Server\Controller;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Http\Message\Response;

use function json_encode;
use function max;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Handles GET /studio/console/activity: system activity log viewer.
 *
 * Shows all system activity: auth events, data changes, admin actions,
 * job completions, and other notable operations across the application.
 */
#[Internal]
final readonly class ActivityLogController
{
    use RendersStudioView;
    /** @var list<string> Event types that constitute "activity" */
    private const array ACTIVITY_TYPES = [
        'http.request',
        'http.response',
        'db.query',
        'job.queued',
        'job.completed',
        'job.failed',
        'scheduler.run',
        'exception',
        'log.entry',
        'notification.sent',
        'feature_flag.eval',
    ];
    /**
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */

    public function __construct(
        private EventStoreInterface $store,
    ) {}

    /**
     * @throws JsonException
     */
    public function handle(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        /** @var string $typeFilter */
        $typeFilter = $params['type'] ?? '';
        /** @var int|string $rawPage */
        $rawPage = $params['page'] ?? 1;
        $page = max(1, (int) $rawPage);
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $filters = ['event_type' => $this->resolveTypeFilter($typeFilter)];

        $events = $this->store->query($filters, $limit, $offset);
        $total = $this->store->count($filters);

        $dataJson = json_encode([
            'events' => $events,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'type_filter' => $typeFilter,
            'available_types' => self::ACTIVITY_TYPES,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $html = $this->renderStudioView('Activity log - Pulsar Studio', 'console/activity', [
            'dataJson' => $dataJson,
        ]);

        return Response::html($html);
    }

    /**
     * Resolve the type filter from query parameter to event types.
     *
     * @return list<string>
     */
    private function resolveTypeFilter(string $filter): array
    {
        if ($filter === '' || $filter === 'all') {
            return self::ACTIVITY_TYPES;
        }

        // Validate the filter is a known type
        foreach (self::ACTIVITY_TYPES as $type) {
            if ($type === $filter) {
                return [$filter];
            }
        }

        return self::ACTIVITY_TYPES;
    }
}
