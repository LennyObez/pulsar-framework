<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Themes\PreviewSessionRepositoryInterface;
use Pulsar\Extension\Cms\Workflow\ContentLockServiceInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function sprintf;

/**
 * Scheduled job that cleans up expired preview sessions and content locks.
 *
 * Runs every 30 minutes to remove stale sessions and locks that were not
 * properly released (e.g., browser closed without saving).
 *
 * @psalm-api Registered with the scheduler by the CMS service provider;
 *            invoked through JobInterface, not instantiated by name.
 */
#[Internal(reason: 'CMS expired session and lock cleanup')]
final readonly class ExpiredSessionCleanupJob implements JobInterface
{
    public function __construct(
        private PreviewSessionRepositoryInterface $previewSessionRepository,
        private ContentLockServiceInterface $contentLockService,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:expired-session-cleanup';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::cron('*/30 * * * *');
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $sessions = $this->previewSessionRepository->deleteExpired();
            $locks = $this->contentLockService->cleanupExpired();

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf(
                    'Cleanup complete: %d expired preview session(s), %d expired lock(s) removed',
                    $sessions,
                    $locks,
                ),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Cleans up expired preview sessions and content locks';
    }
}
