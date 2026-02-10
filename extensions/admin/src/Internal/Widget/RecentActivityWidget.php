<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Widget;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\WidgetInterface;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryEntry;
use Pulsar\Extension\Admin\Internal\Storage\ActionHistoryStoreInterface;

/**
 * Dashboard widget showing recent admin action history.
 */
#[Internal]
final readonly class RecentActivityWidget implements WidgetInterface
{
    public function __construct(
        private readonly ActionHistoryStoreInterface $actionHistory,
    ) {}

    #[Override]
    public function id(): string
    {
        return 'recent_activity';
    }

    #[Override]
    public function label(): string
    {
        return 'Recent Activity';
    }

    #[Override]
    public function size(): string
    {
        return 'large';
    }

    #[Override]
    public function render(): array
    {
        $entries = $this->actionHistory->recent(20);

        $items = array_map(
            static fn(ActionHistoryEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'resource' => $entry->resourceName,
                'record_id' => $entry->recordId,
                'actor' => $entry->actor,
                'timestamp' => $entry->timestamp,
                'success' => $entry->success,
            ],
            $entries,
        );

        return ['entries' => $items];
    }
}
