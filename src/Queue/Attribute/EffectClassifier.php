<?php

declare(strict_types=1);

namespace Pulsar\Queue\Attribute;

use Pulsar\Api\Internal;
use Pulsar\Queue\Exception\QueueException;
use ReflectionClass;

use function count;
use function sprintf;

/**
 * Reads effect classification attributes from job classes via reflection.
 *
 * Enforcement rules:
 * 1. Every job MUST have exactly one of: #[Idempotent], #[SideEffectFree], #[NonIdempotent].
 * 2. #[NonIdempotent] jobs are NOT auto-retried by the worker on failure.
 * 3. In regulated presets, #[NonIdempotent] jobs are rejected at dispatch unless
 *    #[AllowNonIdempotent] is also present on the class.
 * 4. #[AllowNonIdempotent] emits an audit event at dispatch time.
 * 5. Missing effect attribute causes dispatch failure in regulated presets.
 * 6. #[SystemJob] exempts from the subject ID requirement in regulated presets.
 */
#[Internal(reason: 'Implementation detail for effect classification enforcement')]
final readonly class EffectClassifier
{
    /**
     * Determine the effect classification of a job class.
     *
     * @param class-string $jobClass Fully-qualified class name.
     *
     * @throws QueueException When the class has no effect classification attribute.
     */
    public function classify(string $jobClass): EffectClassification
    {
        $reflection = new ReflectionClass($jobClass);
        $classifications = [];

        if (count($reflection->getAttributes(Idempotent::class)) > 0) {
            $classifications[] = EffectClassification::Idempotent;
        }

        if (count($reflection->getAttributes(SideEffectFree::class)) > 0) {
            $classifications[] = EffectClassification::ReadOnly;
        }

        if (count($reflection->getAttributes(NonIdempotent::class)) > 0) {
            $classifications[] = EffectClassification::NonIdempotent;
        }

        if (count($classifications) === 0) {
            throw QueueException::missingEffectClassification($jobClass);
        }

        if (count($classifications) > 1) {
            throw QueueException::missingEffectClassification(
                sprintf('%s (multiple effect attributes found: exactly one required)', $jobClass),
            );
        }

        return $classifications[0];
    }

    /**
     * Check whether a job class declares any effect classification attribute.
     *
     * @param class-string $jobClass Fully-qualified class name.
     */
    public function hasEffectAttribute(string $jobClass): bool
    {
        $reflection = new ReflectionClass($jobClass);

        return count($reflection->getAttributes(Idempotent::class)) > 0
            || count($reflection->getAttributes(SideEffectFree::class)) > 0
            || count($reflection->getAttributes(NonIdempotent::class)) > 0;
    }

    /**
     * Check whether a job class is marked as a system job.
     *
     * @param class-string $jobClass Fully-qualified class name.
     */
    public function isSystemJob(string $jobClass): bool
    {
        $reflection = new ReflectionClass($jobClass);

        return count($reflection->getAttributes(SystemJob::class)) > 0;
    }

    /**
     * Check whether a job class requires AEAD payload encryption.
     *
     * @param class-string $jobClass Fully-qualified class name.
     */
    public function isEncrypted(string $jobClass): bool
    {
        $reflection = new ReflectionClass($jobClass);

        return count($reflection->getAttributes(Encrypted::class)) > 0;
    }

    /**
     * Get the AllowNonIdempotent attribute instance if present on the job class.
     *
     * @param class-string $jobClass Fully-qualified class name.
     */
    public function getAllowNonIdempotent(string $jobClass): ?AllowNonIdempotent
    {
        $reflection = new ReflectionClass($jobClass);
        $attributes = $reflection->getAttributes(AllowNonIdempotent::class);

        if (count($attributes) === 0) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Determine whether a job requires a subject ID.
     *
     * All jobs require a subject ID in regulated presets unless #[SystemJob] is present.
     *
     * @param class-string $jobClass Fully-qualified class name.
     */
    public function requiresSubjectId(string $jobClass): bool
    {
        return !$this->isSystemJob($jobClass);
    }
}
