<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function sprintf;

/**
 * Scheduled job that purges permanently failed webhook events older than 30 days.
 *
 * Executes daily at 2 AM UTC to keep the cms_webhook_events table from growing unbounded.
 *
 * @psalm-api Registered with the scheduler by the CMS service provider;
 *            invoked through JobInterface, not instantiated by name.
 */
#[Internal(reason: 'CMS webhook event cleanup scheduler job')]
final readonly class WebhookRetryCleanupJob implements JobInterface
{
    private const int RETENTION_DAYS = 30;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:webhook-retry-cleanup';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::dailyAt('02:00');
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $cutoff = new DateTimeImmutable(sprintf('-%d days', self::RETENTION_DAYS));

            $result = $this->connection->execute(
                'DELETE FROM cms_webhook_events WHERE processed_at < :cutoff',
                ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf('Webhook event cleanup complete: %d record(s) purged', $result),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Purges webhook events older than 30 days from the cms_webhook_events table';
    }
}
