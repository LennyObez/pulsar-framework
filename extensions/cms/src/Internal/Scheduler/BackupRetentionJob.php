<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Tools\BackupServiceInterface;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function count;
use function sprintf;

/**
 * Scheduled job that enforces backup retention policy.
 *
 * Runs weekly on Sundays at 2 AM UTC. Deletes backups older than
 * the configured retention period (default 30 days).
 */
#[Internal(reason: 'CMS backup retention enforcement')]
final readonly class BackupRetentionJob implements JobInterface
{
    private const int DEFAULT_RETENTION_DAYS = 30;

    public function __construct(
        private BackupServiceInterface $backupService,
        private int $retentionDays = self::DEFAULT_RETENTION_DAYS,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:backup-retention';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::cron('0 2 * * 0');
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $cutoff = new DateTimeImmutable(sprintf('-%d days', $this->retentionDays));
            $backups = $this->backupService->listBackups();
            $deleted = 0;

            foreach ($backups as $backup) {
                if ($backup->createdAt < $cutoff) {
                    $this->backupService->deleteBackup(
                        $backup->id,
                        sprintf('Retention policy: older than %d days', $this->retentionDays),
                        'system:scheduler',
                    );
                    $deleted++;
                }
            }

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf(
                    'Backup retention: %d of %d backup(s) deleted (retention: %d days)',
                    $deleted,
                    count($backups),
                    $this->retentionDays,
                ),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Enforces backup retention policy by deleting old backups';
    }
}
