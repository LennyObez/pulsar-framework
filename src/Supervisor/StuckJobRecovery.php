<?php

declare(strict_types=1);

namespace Pulsar\Supervisor;

use JsonException;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Queue\DeadLetterQueue;
use Pulsar\Queue\JobRecord;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Security\Audit\AuditOutcome;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;
use SodiumException;

use function bin2hex;
use function sprintf;
use function time;

/**
 * Recovers stuck jobs by moving them to the dead-letter queue
 * and recording the healing action for audit purposes.
 */
#[Internal]
final readonly class StuckJobRecovery
{
    private Randomizer $randomizer;

    public function __construct(
        private DeadLetterQueue $deadLetterQueue,
        private ?LoggerInterface $logger = null,
        private ?AuditLogger $auditLogger = null,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer(new Secure());
    }

    /**
     * Recover a single stuck job by dead-lettering it.
     *
     * Logs the recovery to both the application logger and the audit trail.
     *
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    public function recover(JobRecord $stuckJob): HealingAction
    {
        $reason = sprintf(
            'Job "%s" (%s) stuck in processing state — exceeded timeout',
            $stuckJob->id,
            $stuckJob->jobClass,
        );

        $this->deadLetterQueue->store($stuckJob, $reason);

        $this->logger?->warning($reason, [
            'job_id' => $stuckJob->id,
            'job_class' => $stuckJob->jobClass,
            'queue' => $stuckJob->queue,
            'attempts' => $stuckJob->attempts,
        ]);

        $this->auditLogger?->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'supervisor',
            action: 'stuck_job_recovery',
            resource: $stuckJob->id,
            metadata: [
                'job_class' => $stuckJob->jobClass,
                'queue' => $stuckJob->queue,
                'attempts' => $stuckJob->attempts,
            ],
        );

        return new HealingAction(
            id: bin2hex($this->randomizer->getBytes(16)),
            type: HealingActionType::StuckJobRecovery,
            description: $reason,
            performedAt: time(),
            success: true,
            correlationId: $stuckJob->id,
        );
    }
}
