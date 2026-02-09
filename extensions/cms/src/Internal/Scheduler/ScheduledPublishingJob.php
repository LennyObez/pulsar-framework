<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Content\ContentRepositoryInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function count;
use function sprintf;

/**
 * Scheduled job that processes content publishing and unpublishing transitions.
 *
 * Runs every minute to ensure scheduled content goes live or is archived
 * at the precise time configured by editors.
 */
#[Internal(reason: 'CMS scheduled publishing worker')]
final readonly class ScheduledPublishingJob implements JobInterface
{
    public function __construct(
        private ContentRepositoryInterface $contentRepository,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:scheduled-publishing';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::everyMinute();
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $now = new DateTimeImmutable();
            $publishedCount = 0;
            $archivedCount = 0;

            $toPublish = $this->contentRepository->findScheduledForPublishing($now);

            foreach ($toPublish as $content) {
                $updated = $content->publish();
                $this->contentRepository->save($updated);
                $publishedCount++;
            }

            $toUnpublish = $this->contentRepository->findScheduledForUnpublishing($now);

            foreach ($toUnpublish as $content) {
                $updated = $content->archive();
                $this->contentRepository->save($updated);
                $archivedCount++;
            }

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf(
                    'Scheduled publishing complete: %d published, %d archived (%d total)',
                    $publishedCount,
                    $archivedCount,
                    count($toPublish) + count($toUnpublish),
                ),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Processes scheduled content publishing and unpublishing transitions';
    }
}
