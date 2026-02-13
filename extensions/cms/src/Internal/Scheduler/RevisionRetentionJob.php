<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Scheduler;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\InListBuilder;
use Pulsar\Extension\Cms\Content\RevisionRetentionPolicy;
use Pulsar\Scheduler\JobContext;
use Pulsar\Scheduler\JobInterface;
use Pulsar\Scheduler\JobResult;
use Pulsar\Scheduler\Schedule;
use Throwable;

use function count;
use function sprintf;

/**
 * Scheduled job that enforces content revision retention policy.
 *
 * Runs daily at 3 AM UTC. Deletes old revisions according to the configured
 * policy (max count per content, max age, with options to preserve published
 * and first revisions).
 */
#[Internal(reason: 'CMS revision retention enforcement')]
final readonly class RevisionRetentionJob implements JobInterface
{
    public function __construct(
        private ConnectionInterface $connection,
        private RevisionRetentionPolicy $policy = new RevisionRetentionPolicy(),
        private ?string $tenantId = null,
    ) {}

    #[Override]
    public function getName(): string
    {
        return 'cms:revision-retention';
    }

    #[Override]
    public function getSchedule(): Schedule
    {
        return Schedule::cron('0 3 * * *');
    }

    #[Override]
    public function execute(JobContext $context): JobResult
    {
        $startedAt = new DateTimeImmutable();

        try {
            $totalDeleted = 0;

            // Delete revisions older than max age
            if ($this->policy->maxAgeDays > 0) {
                $cutoff = new DateTimeImmutable(sprintf('-%d days', $this->policy->maxAgeDays));
                $totalDeleted += $this->deleteByAge($cutoff);
            }

            // Delete excess revisions per content (keep newest N)
            if ($this->policy->maxRevisionsPerContent > 0) {
                $totalDeleted += $this->deleteExcessPerContent();
            }

            return JobResult::success(
                $this->getName(),
                $startedAt,
                sprintf(
                    'Revision retention: %d revision(s) deleted (max_per_content=%d, max_age=%d days)',
                    $totalDeleted,
                    $this->policy->maxRevisionsPerContent,
                    $this->policy->maxAgeDays,
                ),
            );
        } catch (Throwable $e) {
            return JobResult::failure($this->getName(), $startedAt, $e);
        }
    }

    #[Override]
    public function getDescription(): string
    {
        return 'Enforces revision retention policy by deleting old content revisions';
    }

    private function deleteByAge(DateTimeImmutable $cutoff): int
    {
        $sql = 'DELETE FROM cms_content_revisions WHERE created_at < :cutoff';
        $bindings = ['cutoff' => $cutoff->format('c')];

        if ($this->tenantId !== null) {
            $sql .= ' AND content_id IN (SELECT id FROM cms_contents WHERE tenant_id = :tenant_id)';
            $bindings['tenant_id'] = $this->tenantId;
        }

        if ($this->policy->keepPublished) {
            $sql .= ' AND is_published = 0';
        }

        if ($this->policy->keepFirstRevision) {
            $sql .= ' AND revision_number > 1';
        }

        return $this->connection->execute($sql, $bindings);
    }

    private function deleteExcessPerContent(): int
    {
        // Find content items with more revisions than the limit
        $countSql = 'SELECT r.content_id, COUNT(*) AS cnt FROM cms_content_revisions r';
        $bindings = ['max_revisions' => $this->policy->maxRevisionsPerContent];

        if ($this->tenantId !== null) {
            $countSql .= ' INNER JOIN cms_contents c ON c.id = r.content_id WHERE c.tenant_id = :tenant_id';
            $bindings['tenant_id'] = $this->tenantId;
        }

        $countSql .= ' GROUP BY r.content_id HAVING COUNT(*) > :max_revisions';

        $result = $this->connection->query($countSql, $bindings);

        $totalDeleted = 0;

        foreach ($result->rows as $row) {
            $contentId = $row->getString('content_id');
            $totalDeleted += $this->pruneRevisionsForContent($contentId);
        }

        return $totalDeleted;
    }

    private function pruneRevisionsForContent(string $contentId): int
    {
        // Get IDs of revisions to keep (newest N)
        $keepSql = 'SELECT id FROM cms_content_revisions WHERE content_id = :content_id';
        $bindings = ['content_id' => $contentId];

        if ($this->policy->keepPublished) {
            $keepSql .= ' AND is_published = 0';
        }

        if ($this->policy->keepFirstRevision) {
            $keepSql .= ' AND revision_number > 1';
        }

        $keepSql .= ' ORDER BY created_at DESC';

        $allRevisions = $this->connection->query($keepSql, $bindings);
        $toDelete = [];
        $kept = 0;

        foreach ($allRevisions->rows as $row) {
            if ($kept < $this->policy->maxRevisionsPerContent) {
                $kept++;
            } else {
                $toDelete[] = $row->getString('id');
            }
        }

        if ($toDelete === []) {
            return 0;
        }

        $driver = $this->connection->driver();
        $inClause = InListBuilder::compile($driver, 'id', 'id', count($toDelete));
        $params = InListBuilder::expandParams($driver, 'id', $toDelete);

        return $this->connection->execute(
            "DELETE FROM cms_content_revisions WHERE $inClause",
            $params,
        );
    }
}
