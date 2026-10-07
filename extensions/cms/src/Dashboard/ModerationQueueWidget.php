<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Dashboard;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Comments\CommentRepositoryInterface;

use function count;

/**
 * Dashboard widget showing pending comment moderation count with urgency badge.
 *
 * Comments pending longer than 24 hours are flagged as urgent.
 *
 * @psalm-api Resolved by the admin DashboardWidget registry; not new'd by name.
 */
#[Internal(reason: 'CMS dashboard widget; implementation detail')]
final readonly class ModerationQueueWidget implements DashboardWidgetInterface
{
    private const int URGENT_THRESHOLD_HOURS = 24;

    public function __construct(
        private CommentRepositoryInterface $commentRepository,
        private ?string $tenantId = null,
    ) {}

    public function getName(): string
    {
        return 'moderation_queue';
    }

    public function getData(): array
    {
        $pending = $this->commentRepository->findPendingModeration(
            tenantId: $this->tenantId,
            perPage: 100,
        );

        $urgentCount = 0;
        $urgentThreshold = new DateTimeImmutable('-' . self::URGENT_THRESHOLD_HOURS . ' hours');

        foreach ($pending->items as $comment) {
            if ($comment->createdAt < $urgentThreshold) {
                $urgentCount++;
            }
        }

        return [
            'pending_count' => $pending->total ?? count($pending->items),
            'urgent_count' => $urgentCount,
            'moderation_url' => '/admin/cms/comments?status=pending',
            'urgent_threshold_hours' => self::URGENT_THRESHOLD_HOURS,
        ];
    }

    public function getTemplate(): string
    {
        return 'dashboard/widgets/moderation-queue';
    }
}
