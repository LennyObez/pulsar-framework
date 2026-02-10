<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Seo\LinkHealthServiceInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function count;
use function sprintf;

/**
 * Scheduled job that runs a full link health check across all published content.
 *
 * Executes daily at 3 AM UTC to detect broken links, missing images,
 * and orphaned internal references.
 */
#[Internal(reason: 'CMS scheduled link health check')]
final readonly class LinkHealthCheckJob implements JobInterface
{
    public function __construct(
        private LinkHealthServiceInterface $linkHealthService,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:link-health-check';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::dailyAt('03:00');
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $results = $this->linkHealthService->checkAll();
            $broken = count($results);

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf('Link health check complete: %d issue(s) found', $broken),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Runs a full link health check across all published CMS content';
    }
}
