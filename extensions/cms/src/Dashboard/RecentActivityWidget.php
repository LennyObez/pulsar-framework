<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use Pulsar\Api\Internal;

use function array_map;

/**
 * Dashboard widget showing the last 10 audit events filtered to CMS actions.
 *
 * Displays content published, comment moderated, theme changed, and similar events.
 *
 * @psalm-api Resolved by the admin DashboardWidget registry; not new'd by name.
 */
#[Internal(reason: 'CMS dashboard widget; implementation detail')]
final readonly class RecentActivityWidget implements DashboardWidgetInterface
{
    private const string CMS_ACTION_PREFIX = 'cms.';

    public function __construct(
        private AuditQueryInterface $auditQuery,
    ) {}

    public function getName(): string
    {
        return 'recent_activity';
    }

    public function getData(): array
    {
        $entries = $this->auditQuery->getRecent(
            actionPrefix: self::CMS_ACTION_PREFIX,
        );

        return [
            'entries' => array_map(static fn($entry) => [
                'id' => $entry->id,
                'event' => $entry->event->value,
                'outcome' => $entry->outcome->value,
                'actor' => $entry->actor,
                'action' => $entry->action,
                'resource' => $entry->resource,
                'timestamp' => $entry->timestamp->format('c'),
            ], $entries),
        ];
    }

    public function getTemplate(): string
    {
        return 'dashboard/widgets/recent-activity';
    }
}
