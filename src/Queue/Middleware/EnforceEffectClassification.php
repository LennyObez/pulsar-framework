<?php

declare(strict_types=1);

namespace Pulsar\Queue\Middleware;

use Closure;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pulsar\Api\Internal;
use Pulsar\Queue\Attribute\EffectClassification;
use Pulsar\Queue\Attribute\EffectClassifier;
use Pulsar\Queue\Envelope\JobEnvelope;
use Pulsar\Queue\Event\NonIdempotentJobAllowed;
use Pulsar\Queue\Exception\QueueException;

/**
 * Validates effect classification attributes and enforces regulated preset rules.
 *
 * Enforcement rules applied:
 * 1. Every job MUST have exactly one effect attribute (#[Idempotent], #[SideEffectFree],
 *    or #[NonIdempotent]); throws if missing.
 * 2. #[NonIdempotent] jobs are rejected unless #[AllowNonIdempotent] is also present.
 *    When allowed, an audit event is emitted.
 * 3. Subject ID is mandatory unless #[SystemJob] is present on the job class.
 *
 * This middleware is intended for regulated presets only. Non-regulated environments
 * should omit it from the pipeline for lower overhead.
 */
#[Internal(reason: 'Effect classification enforcement is an implementation detail of regulated queue processing')]
final readonly class EnforceEffectClassification implements JobMiddlewareInterface
{
    public function __construct(
        private EffectClassifier $classifier,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    #[Override]
    public function handle(JobEnvelope $envelope, Closure $next): mixed
    {
        /** @var class-string $jobClass */
        $jobClass = $envelope->jobClass;

        $classification = $this->classifier->classify($jobClass);

        if ($classification === EffectClassification::NonIdempotent) {
            $this->enforceNonIdempotentAllowance($jobClass);
        }

        if ($this->classifier->requiresSubjectId($jobClass) && $envelope->subjectId === null) {
            throw QueueException::missingSubjectId($jobClass);
        }

        return $next($envelope);
    }

    /**
     * Ensure #[NonIdempotent] jobs have an explicit #[AllowNonIdempotent] annotation.
     *
     * When the allowance is present, dispatches an audit event recording the
     * reason and reviewer for compliance traceability.
     */
    /**
     * @param class-string $jobClass
     */
    private function enforceNonIdempotentAllowance(string $jobClass): void
    {
        $allowance = $this->classifier->getAllowNonIdempotent($jobClass);

        if ($allowance === null) {
            throw QueueException::nonIdempotentNotAllowed($jobClass);
        }

        $this->eventDispatcher->dispatch(new NonIdempotentJobAllowed(
            jobClass: $jobClass,
            reason: $allowance->reason,
            reviewer: $allowance->reviewer,
        ));
    }
}
